<?php

namespace Tests\Unit;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
use App\Services\Chatbot\ConversationManager;
use App\Services\External\AI\AIServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\PatientResolver;
use App\Services\External\Agenda\ProfessionalResolver;
use App\Services\External\Agenda\ServiceResolver;
use App\Services\Intent\IntentDetectorInterface;
use App\Services\Response\ResponseGenerator;
use App\Services\Slots\DateExtractorInterface;
use App\Services\Slots\ServiceExtractorInterface;
use App\Services\Slots\TimeExtractorInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ChatbotAppointmentCreationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_successful_creation_persists_the_real_backend_id_and_does_not_confirm(): void
    {
        $this->fakeAvailabilityAndCreation();
        $conversation = $this->conversation();

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('Número de cita: 847', $reply);
        $this->assertSame(847, $conversation->getContextValue('slots.appointment_id'));
        $this->assertSame('created', $conversation->getContextValue('slots.appointment_creation_status'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/citas')
            && $request->data() === [
                'paciente_id' => 15,
                'profesional_id' => 7,
                'servicio_id' => 3,
                'fecha_hora_inicio' => '2026-09-10 16:00:00',
            ]);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/confirmar'));
    }

    public function test_unavailable_time_never_posts_or_creates_an_id(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => false]])]);
        $conversation = $this->conversation();

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('no está disponible', $reply);
        $this->assertNull($conversation->getContextValue('slots.appointment_id'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/citas'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creationErrorStatuses')]
    public function test_creation_http_errors_never_report_success(int $status): void
    {
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => Http::response(['message' => 'failure'], $status),
        ]);
        $conversation = $this->conversation();

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringNotContainsString('registrada correctamente', $reply);
        $this->assertNull($conversation->getContextValue('slots.appointment_id'));
        $this->assertSame('failed', $conversation->getContextValue('slots.appointment_creation_status'));
    }

    public static function creationErrorStatuses(): array
    {
        return [[401], [403], [404], [409], [422], [500], [503]];
    }

    public function test_creation_timeout_reports_uncertain_result_without_inventing_an_id(): void
    {
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => function (): never {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);
        $conversation = $this->conversation();

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('no pude confirmar', strtolower($reply));
        $this->assertNull($conversation->getContextValue('slots.appointment_id'));
    }

    public function test_http_201_without_an_id_is_not_success(): void
    {
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => Http::response(['data' => []], 201),
        ]);
        $conversation = $this->conversation();

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringNotContainsString('registrada correctamente', $reply);
        $this->assertNull($conversation->getContextValue('slots.appointment_id'));
    }

    public function test_availability_error_prevents_creation(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response([], 500)]);
        $conversation = $this->conversation();

        $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/citas'));
        $this->assertNull($conversation->getContextValue('slots.appointment_id'));
    }

    public function test_missing_required_slot_prevents_both_requests(): void
    {
        Http::fake();
        $conversation = $this->conversation();
        $conversation->setContextValue('slots.paciente_id', null);

        $reply = $this->invoke($this->chatbot(), 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('documento', strtolower($reply));
        Http::assertNothingSent();
    }

    public function test_repeated_check_after_creation_does_not_duplicate_post(): void
    {
        $postCount = 0;
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => function () use (&$postCount) {
                $postCount++;

                return Http::response(['data' => [
                    'id' => 847,
                    'servicio' => ['nombre' => 'Limpieza Dental'],
                    'fecha' => '2026-09-10',
                    'hora' => '16:00:00',
                    'fecha_hora_inicio' => '2026-09-10 16:00:00',
                    'estado' => ['codigo' => 'PENDIENTE'],
                ]], 201);
            },
        ]);
        $conversation = $this->conversation();
        $chatbot = $this->chatbot();

        $this->invoke($chatbot, 'checkRealAvailability', $conversation);
        $this->invoke($chatbot, 'checkRealAvailability', $conversation);

        $this->assertSame(1, $postCount);
    }

    public function test_date_change_rechecks_and_creates_using_the_new_date(): void
    {
        $this->fakeAvailabilityAndCreation();
        $conversation = $this->conversation();
        $dateExtractor = Mockery::mock(DateExtractorInterface::class);
        $dateExtractor->shouldReceive('extract')->with('2026-09-11 a las 16:00')->andReturn('2026-09-11');
        $timeExtractor = Mockery::mock(TimeExtractorInterface::class);
        $timeExtractor->shouldReceive('extract')->with('2026-09-11 a las 16:00')->andReturn('16:00');
        $chatbot = $this->chatbot($dateExtractor, $timeExtractor);

        $this->invoke($chatbot, 'onAppointmentTimeProvided', $conversation, '2026-09-11 a las 16:00');

        $this->assertSame('2026-09-11', $conversation->getContextValue('slots.date'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->data()['fecha_hora_inicio'] === '2026-09-11 16:00:00');
    }

    private function fakeAvailabilityAndCreation(): void
    {
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => Http::response(['data' => [
                'id' => 847,
                'servicio' => ['nombre' => 'Limpieza Dental'],
                'fecha' => '2026-09-10',
                'hora' => '16:00:00',
                'fecha_hora_inicio' => '2026-09-10 16:00:00',
                'estado' => ['codigo' => 'PENDIENTE'],
            ]], 201),
        ]);
    }

    private function conversation(): Conversation
    {
        return new Conversation([
            'state' => ConversationState::IN_PROGRESS,
            'user_identifier' => 'external-user',
            'context' => ['slots' => [
                'paciente_id' => 15,
                'servicio_id' => 3,
                'profesional_id' => 7,
                'professional' => 'Juan Pérez',
                'date' => '2026-09-10',
                'time' => '16:00',
            ]],
        ]);
    }

    private function chatbot(?DateExtractorInterface $dateExtractor = null, ?TimeExtractorInterface $timeExtractor = null): ChatbotService
    {
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
        $agenda = new ApiAgendaService(app(Factory::class));

        return new ChatbotService(
            Mockery::mock(IntentDetectorInterface::class),
            new ConversationManager,
            new ResponseGenerator,
            $agenda,
            Mockery::mock(AIServiceInterface::class),
            Mockery::mock(ServiceExtractorInterface::class),
            $dateExtractor ?? Mockery::mock(DateExtractorInterface::class),
            $timeExtractor ?? Mockery::mock(TimeExtractorInterface::class),
            new PatientResolver($agenda),
            new ServiceResolver($agenda),
            new ProfessionalResolver($agenda),
        );
    }

    private function invoke(ChatbotService $chatbot, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($chatbot, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($chatbot, ...$arguments);
    }
}


<?php

namespace Tests\Unit;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
use App\Services\Chatbot\ConversationManager;
use App\Services\External\AI\AIServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use App\Services\External\Agenda\ClinicApiException;
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

class ChatbotAvailabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_available_time_creates_the_appointment_once(): void
    {
        Http::fake([
            'http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]]),
            'http://clinic.test/api/citas' => Http::response(['data' => [
                'id' => 123,
                'servicio' => ['nombre' => 'Limpieza Dental'],
                'fecha' => '2026-09-10',
                'hora' => '16:00:00',
                'fecha_hora_inicio' => '2026-09-10 16:00:00',
                'estado' => ['codigo' => 'PENDIENTE'],
            ]], 201),
        ]);
        $conversation = $this->conversation();
        $chatbot = $this->chatbot();

        $reply = $this->invoke($chatbot, 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('fue registrada correctamente', $reply);
        $this->assertSame(123, $conversation->getContextValue('slots.appointment_id'));
        $this->assertSame('created', $conversation->getContextValue('slots.appointment_creation_status'));
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/api/disponibilidad')
                && $query === [
                    'servicio_id' => '3',
                    'profesional_id' => '7',
                    'fecha' => '2026-09-10',
                    'hora' => '16:00',
                ];
        });
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/citas'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/confirmar'));
    }

    public function test_unavailable_time_preserves_context_and_does_not_invent_slots(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => false]])]);
        $conversation = $this->conversation();
        $chatbot = $this->chatbot();

        $reply = $this->invoke($chatbot, 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('no está disponible', $reply);
        $this->assertStringNotContainsString('16:30', $reply);
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));
        $this->assertSame(15, $conversation->getContextValue('slots.paciente_id'));
        $this->assertSame(3, $conversation->getContextValue('slots.servicio_id'));
        $this->assertSame(7, $conversation->getContextValue('slots.profesional_id'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/citas'));
    }

    public function test_api_error_is_not_interpreted_as_unavailable(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response([], 500)]);
        $conversation = $this->conversation();
        $chatbot = $this->chatbot();

        $reply = $this->invoke($chatbot, 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('No puedo verificar', $reply);
        $this->assertFalse($conversation->getContextValue('slots.availability_checked'));
        $this->assertNull($conversation->getContextValue('slots.availability_result'));
    }

    public function test_missing_time_does_not_call_the_api(): void
    {
        Http::fake();
        $conversation = $this->conversation();
        $conversation->setContextValue('slots.time', null);
        $chatbot = $this->chatbot();

        $reply = $this->invoke($chatbot, 'checkRealAvailability', $conversation);

        $this->assertStringContainsString('hora', $reply);
        Http::assertNothingSent();
    }

    public function test_time_change_rechecks_only_the_new_time(): void
    {
        Http::fake(['http://clinic.test/api/disponibilidad*' => Http::response(['data' => ['available' => true]])]);
        $conversation = $this->conversation();
        $timeExtractor = Mockery::mock(TimeExtractorInterface::class);
        $timeExtractor->shouldReceive('extract')->with('17:00')->andReturn('17:00');
        $dateExtractor = Mockery::mock(DateExtractorInterface::class);
        $dateExtractor->shouldReceive('extract')->with('17:00')->andReturn(null);
        $chatbot = $this->chatbot($timeExtractor, $dateExtractor);

        $this->invoke($chatbot, 'onAppointmentTimeProvided', $conversation, '17:00');

        $this->assertSame('17:00', $conversation->getContextValue('slots.time'));
        $this->assertSame('2026-09-10', $conversation->getContextValue('slots.date'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'hora=17%3A00'));
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

    private function chatbot(?TimeExtractorInterface $timeExtractor = null, ?DateExtractorInterface $dateExtractor = null): ChatbotService
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

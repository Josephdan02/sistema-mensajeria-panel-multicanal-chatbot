<?php

namespace Tests\Unit;

use App\Enums\ConversationState;
use App\Enums\Intent;
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

class ChatbotEntityResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_real_agenda_mode_collects_entity_ids_without_calling_appointment_endpoints(): void
    {
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
        Http::fake([
            'http://clinic.test/api/pacientes*' => Http::response(['data' => [
                'id' => 15, 'tipo_documento' => 'DNI', 'numero_documento' => '12345678',
                'nombres' => 'Ana', 'apellidos' => 'Gómez',
            ]]),
            'http://clinic.test/api/servicios' => Http::response(['data' => [
                ['id' => 3, 'codigo' => 'LIMPIEZA', 'nombre' => 'Limpieza Dental'],
            ]]),
            'http://clinic.test/api/profesionales' => Http::response(['data' => [
                ['id' => 7, 'nombres' => 'Juan', 'apellidos' => 'Pérez'],
            ]]),  
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

        $conversation = new Conversation([
            'state' => ConversationState::IDLE,
            'user_identifier' => 'external-user',
            'context' => [
                'slots' => [
                    'service' => 'Limpieza dental',
                    'date' => '2026-09-10',
                    'time' => '16:00',
                ],
            ],
        ]);
        $intentDetector = Mockery::mock(IntentDetectorInterface::class);
        $intentDetector->shouldReceive('detect')->andReturn(Intent::REQUEST_APPOINTMENT);
        $serviceExtractor = Mockery::mock(ServiceExtractorInterface::class);
        $serviceExtractor->shouldReceive('extract')->andReturn('Limpieza dental');
        $dateExtractor = Mockery::mock(DateExtractorInterface::class);
        $dateExtractor->shouldReceive('extract')->andReturn('2026-09-10');
        $timeExtractor = Mockery::mock(TimeExtractorInterface::class);
        $timeExtractor->shouldReceive('extract')->andReturn('16:00');

        $chatbot = new ChatbotService(
            $intentDetector,
            new ConversationManager,
            new ResponseGenerator,
            new ApiAgendaService(app(Factory::class)),
            Mockery::mock(AIServiceInterface::class),
            $serviceExtractor,
            $dateExtractor,
            $timeExtractor,
            new PatientResolver(new ApiAgendaService(app(Factory::class))),
            new ServiceResolver(new ApiAgendaService(app(Factory::class))),
            new ProfessionalResolver(new ApiAgendaService(app(Factory::class))),
        );

        $this->assertStringContainsString('documento', $this->invoke($chatbot, 'startAppointmentRequest', $conversation));
        $conversation->setContextValue('slots.patient_document', '12345678');
        $this->assertStringContainsString('profesional', $this->invoke($chatbot, 'resolvePatient', $conversation, '12345678'));
        $this->invoke($chatbot, 'resolveProfessional', $conversation, 'Dr. Juan Pérez');

        $this->assertSame(15, $conversation->getContextValue('slots.paciente_id'));
        $this->assertSame(3, $conversation->getContextValue('slots.servicio_id'));
        $this->assertSame(7, $conversation->getContextValue('slots.profesional_id'));
        $this->assertSame('2026-09-10', $conversation->getContextValue('slots.date'));
        $this->assertSame('16:00', $conversation->getContextValue('slots.time'));
        $this->assertNull($conversation->getContextValue('awaiting'));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/confirmar')
            || str_contains($request->url(), '/cancelar')
            || str_contains($request->url(), '/reprogramar'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/disponibilidad'));
    }

    private function invoke(ChatbotService $chatbot, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($chatbot, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($chatbot, ...$arguments);
    }
}


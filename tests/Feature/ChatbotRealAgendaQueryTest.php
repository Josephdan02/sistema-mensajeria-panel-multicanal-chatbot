<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\MockAppointment;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotRealAgendaQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sustituye el singleton de agenda por ApiAgendaService apuntando a un host
     * falsificado, para probar el cableado real (DNI -> paciente -> citas)
     * sin depender de la red.
     */
    private function useRealAgenda(): void
    {
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
        config()->set('services.clinic_api.timeout', 5);
        $this->app->instance(AgendaServiceInterface::class, new ApiAgendaService(app(Factory::class)));
    }

    public function test_greeting_presents_the_assistant_and_its_options(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'greeting-user',
            'message' => 'hola',
        ]);

        $response->assertOk()->assertJsonPath('data.intent', 'greeting');

        $message = $response->json('message');

        $this->assertStringContainsString('asistente virtual', $message);
        $this->assertStringContainsString('Consultar tus citas', $message);
        $this->assertStringContainsString('Agendar una cita', $message);
        $this->assertStringContainsString('Reprogramar una cita', $message);
        $this->assertStringContainsString('Cancelar una cita', $message);
        $this->assertStringContainsString('¿Qué deseas hacer?', $message);
    }

    public function test_greeting_variants_are_detected(): void
    {
        foreach (['buen día', 'buenos días', 'buenas tardes'] as $greeting) {
            $response = $this->postJson('/api/chat', [
                'userIdentifier' => 'greeting-variants-user',
                'message' => $greeting,
            ]);

            $response->assertOk()->assertJsonPath('data.intent', 'greeting');
        }
    }

    public function test_query_appointments_phrases_are_detected_as_check_appointment(): void
    {
        foreach ([
            'Quiero consultar mis citas',
            'consultar mis citas',
            'quiero ver mis citas',
            'ver mis citas',
            'cuáles son mis citas',
            'tengo citas',
            'quiero saber mis citas',
            'consultar mi cita',
            'consultar cita',
            'ver cita',
        ] as $phrase) {
            $response = $this->postJson('/api/chat', [
                'userIdentifier' => 'intent-user',
                'message' => $phrase,
            ]);

            $response->assertOk()->assertJsonPath('data.intent', 'check_appointment');
        }
    }

    public function test_real_agenda_flow_asks_for_the_document_to_query_appointments(): void
    {
        $this->useRealAgenda();
        Http::fake();

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'real-query-user',
            'message' => 'Quiero consultar mis citas',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'check_appointment')
            ->assertJsonPath('message', 'Claro. Para consultar tus citas necesito tu número de DNI.');

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));
        $this->assertSame('appointment_query_document', $conversation->getContextValue('awaiting'));

        Http::assertNothingSent();
    }

    public function test_real_agenda_flow_resolves_the_patient_and_lists_his_appointments(): void
    {
        $this->useRealAgenda();

        Http::fake([
            'http://clinic.test/api/pacientes?numero_documento=90000001' => Http::response([
                'data' => [
                    'id' => 1,
                    'tipo_documento' => 'DNI',
                    'numero_documento' => '90000001',
                    'nombres' => 'Paciente',
                    'apellidos' => 'Prueba E2E',
                ],
            ]),
            'http://clinic.test/api/pacientes/1/citas' => Http::response([
                'data' => [
                    [
                        'id' => 5,
                        'servicio' => ['nombre' => 'Limpieza dental'],
                        'fecha' => '2026-09-10',
                        'hora' => '16:00:00',
                        'fecha_hora_inicio' => '2026-09-10T21:00:00.000000Z',
                        'estado' => ['codigo' => 'PENDIENTE'],
                    ],
                ],
            ]),
        ]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'real-query-user',
            'message' => 'quiero consultar mis citas',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'real-query-user',
            'message' => '90000001',
        ]);

        $response->assertOk();

        $message = $response->json('message');

        $this->assertStringContainsString('Limpieza dental', $message);
        $this->assertStringContainsString('2026-09-10', $message);
        $this->assertStringContainsString('16:00', $message);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://clinic.test/api/pacientes?numero_documento=90000001');
        Http::assertSent(fn ($request): bool => $request->url() === 'http://clinic.test/api/pacientes/1/citas'
            && $request->header('X-API-Key')[0] === 'test-api-key');

        // El DNI nunca se interpreta como cita_id: no se consulta ninguna cita individual.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/citas/'));

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_real_agenda_flow_returns_a_friendly_message_when_the_patient_is_missing(): void
    {
        $this->useRealAgenda();
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['message' => 'missing'], 404)]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'missing-patient-user',
            'message' => 'quiero consultar mis citas',
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $start->json('data.conversation_id'),
            'userIdentifier' => 'missing-patient-user',
            'message' => '90000001',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'No encontré un paciente registrado con ese documento. Verifica el número e inténtalo de nuevo.');
    }

    public function test_real_agenda_flow_returns_a_friendly_message_when_the_api_fails(): void
    {
        $this->useRealAgenda();
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['message' => 'down'], 503)]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'api-failure-user',
            'message' => 'quiero consultar mis citas',
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $start->json('data.conversation_id'),
            'userIdentifier' => 'api-failure-user',
            'message' => '90000001',
        ]);

        // Debe ser HTTP 200 con mensaje amigable, nunca un HTTP 500.
        $response->assertOk()
            ->assertJsonPath('message', 'No pude verificar tus datos en este momento. Por favor, inténtalo nuevamente más tarde.');
    }

    public function test_real_agenda_flow_returns_a_friendly_message_when_listing_appointments_fails(): void
    {
        $this->useRealAgenda();

        Http::fake([
            'http://clinic.test/api/pacientes?numero_documento=90000001' => Http::response([
                'data' => ['id' => 1, 'numero_documento' => '90000001', 'nombres' => 'Paciente', 'apellidos' => 'Prueba E2E'],
            ]),
            'http://clinic.test/api/pacientes/1/citas' => Http::response(['message' => 'boom'], 500),
        ]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'list-failure-user',
            'message' => 'quiero consultar mis citas',
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $start->json('data.conversation_id'),
            'userIdentifier' => 'list-failure-user',
            'message' => '90000001',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'No pude verificar tus citas en este momento. Por favor, inténtalo nuevamente más tarde.');
    }

    public function test_mock_agenda_supports_the_new_appointments_by_patient_contract(): void
    {
        MockAppointment::create([
            'user_identifier' => '7',
            'service' => 'Limpieza dental',
            'date' => '2026-09-10',
            'time' => '16:00',
            'status' => 'pending_confirmation',
        ]);

        $appointments = $this->app->make(AgendaServiceInterface::class)->appointmentsByPatient(7);

        $this->assertCount(1, $appointments);
        $this->assertSame('Limpieza dental', $appointments[0]['service']);
    }
}
<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\ApiAgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotRealAgendaManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sustituye el singleton de agenda por ApiAgendaService apuntando a un host
     * falsificado, para probar el cableado real (DNI -> paciente -> citas ->
     * seleccionar -> cancelar/reprogramar) sin depender de la red.
     */
    private function useRealAgenda(): void
    {
        config()->set('services.clinic_api.url', 'http://clinic.test');
        config()->set('services.clinic_api.key', 'test-api-key');
        config()->set('services.clinic_api.timeout', 5);
        $this->app->instance(AgendaServiceInterface::class, new ApiAgendaService(app(Factory::class)));
    }

    private function fakePatient(array $appointments = []): void
    {
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
            'http://clinic.test/api/pacientes/1/citas' => Http::response(['data' => $appointments]),
            'http://clinic.test/api/citas/5/cancelar' => Http::response([
                'data' => [
                    'id' => 5,
                    'servicio' => ['nombre' => 'Limpieza dental'],
                    'fecha' => '2026-09-10',
                    'hora' => '16:00:00',
                    'fecha_hora_inicio' => '2026-09-10T21:00:00.000000Z',
                    'estado' => ['codigo' => 'CANCELADA'],
                ],
            ]),
            'http://clinic.test/api/citas/10/cancelar' => Http::response([
                'data' => [
                    'id' => 10,
                    'servicio' => ['nombre' => 'Ortodoncia'],
                    'fecha' => '2026-09-15',
                    'hora' => '09:00:00',
                    'fecha_hora_inicio' => '2026-09-15T14:00:00.000000Z',
                    'estado' => ['codigo' => 'CANCELADA'],
                ],
            ]),
            'http://clinic.test/api/citas/5/reprogramar' => Http::response([
                'data' => [
                    'id' => 5,
                    'servicio' => ['nombre' => 'Limpieza dental'],
                    'fecha' => '2026-09-20',
                    'hora' => '18:30:00',
                    'fecha_hora_inicio' => '2026-09-21T00:30:00.000000Z',
                    'estado' => ['codigo' => 'PENDIENTE'],
                ],
            ]),
        ]);
    }

    private function appointment(int $id, string $service, string $date, string $time, string $status): array
    {
        return [
            'id' => $id,
            'servicio' => ['nombre' => $service],
            'fecha' => $date,
            'hora' => $time.':00',
            'fecha_hora_inicio' => $date.'T12:00:00.000000Z',
            'estado' => ['codigo' => $status],
        ];
    }    

    public function test_real_cancel_flow_asks_for_the_document(): void
    {
        $this->useRealAgenda();
        Http::fake();

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'cancel_appointment')
            ->assertJsonPath('message', 'Claro. Para cancelar tu cita necesito tu número de DNI.');

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));
        $this->assertSame('cancel_query_document', $conversation->getContextValue('awaiting'));

        Http::assertNothingSent();
    }

    public function test_real_cancel_flow_confirms_before_cancelling_a_single_appointment(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([$this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE')]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $response->assertOk();

        $message = $response->json('message');

        $this->assertStringContainsString('¿Confirmas que deseas cancelar', $message);
        $this->assertStringContainsString('Limpieza dental', $message);
        $this->assertStringContainsString('2026-09-10', $message);
        $this->assertStringContainsString('16:00', $message);

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('cancel_confirmation', $conversation->getContextValue('awaiting'));
        $this->assertSame(5, $conversation->getContextValue('slots.appointment_id'));

        // Todavía no se llamó al endpoint de cancelación: exige confirmación.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/cancelar'));
    }

    public function test_real_cancel_flow_confirmed_calls_the_cancel_endpoint(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([$this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE')]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => 'sí',
        ]);

        $response->assertOk()->assertJsonPath('message', 'Tu cita fue cancelada correctamente. Si deseas agendar una nueva, avísame.');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'http://clinic.test/api/citas/5/cancelar'
            && $request->header('X-API-Key')[0] === 'test-api-key');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/reprogramar'));

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_real_cancel_flow_declined_keeps_the_appointment_without_mutations(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([$this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE')]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => 'no',
        ]);

        $response->assertOk()->assertJsonPath('message', 'De acuerdo, tu cita se mantiene sin cambios. ¿Necesitas algo más?');

        // Sin confirmación no se toca ninguna cita en CABANILLAS.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/citas/'));

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_real_cancel_flow_with_multiple_appointments_asks_for_a_selection(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([
            $this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE'),
            $this->appointment(10, 'Ortodoncia', '2026-09-15', '09', 'CONFIRMADA'),
        ]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $response->assertOk();

        $message = $response->json('message');

        $this->assertStringContainsString('¿Cuál deseas cancelar?', $message);
        $this->assertStringContainsString('1. Limpieza dental', $message);
        $this->assertStringContainsString('2. Ortodoncia', $message);

        $selected = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '2',
        ]);

        $selected->assertOk();

        $this->assertStringContainsString('Ortodoncia', $selected->json('message'));
        $this->assertStringContainsString('¿Confirmas que deseas cancelar', $selected->json('message'));

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => 'sí',
        ]);

        // Solo la cita seleccionada (10) se cancela; la 5 queda intacta.
        Http::assertSent(fn ($request): bool => $request->url() === 'http://clinic.test/api/citas/10/cancelar');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'http://clinic.test/api/citas/5/cancelar');
    }

    public function test_real_cancel_flow_without_appointments_returns_a_controlled_message(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $response->assertOk()->assertJsonPath('message', 'No encontramos ninguna cita registrada a tu nombre. ¿Deseas agendar una nueva?');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_real_cancel_flow_with_unknown_patient_is_friendly(): void
    {
        $this->useRealAgenda();
        Http::fake(['http://clinic.test/api/pacientes*' => Http::response(['message' => 'missing'], 404)]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'cancelar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '99999999',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'No encontré un paciente registrado con ese documento. Verifica el número e inténtalo de nuevo.');
    }

    public function test_real_reschedule_flow_asks_for_the_document(): void
    {
        $this->useRealAgenda();
        Http::fake();

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'reprogramar mi cita',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'reschedule_appointment')
            ->assertJsonPath('message', 'Claro. Para reprogramar tu cita necesito tu número de DNI.');

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));
        $this->assertSame('reschedule_query_document', $conversation->getContextValue('awaiting'));

        Http::assertNothingSent();
    }

    public function test_real_reschedule_flow_single_appointment_reprograms_with_date_and_time(): void
    {
        $this->useRealAgenda();
        $this->fakePatient([$this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE')]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'reprogramar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $askDate = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);

        $askDate->assertOk();

        $this->assertStringContainsString('¿Qué fecha te gustaría?', $askDate->json('message'));

        $askTime = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '2026-09-20',
        ]);

        $askTime->assertOk();

        $this->assertStringContainsString('¿A qué hora prefieres? (ej: 10:30)', $askTime->json('message'));

        $rescheduled = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '18:30',
        ]);

        $rescheduled->assertOk();

        $this->assertStringContainsString('Tu cita fue reprogramada para el 2026-09-20 a las 18:30', $rescheduled->json('message'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'http://clinic.test/api/citas/5/reprogramar'
            && $request->data()['fecha_hora_inicio'] === '2026-09-20 18:30:00'
            && $request->header('X-API-Key')[0] === 'test-api-key');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/cancelar'));

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_real_reschedule_flow_conflict_keeps_asking_for_a_time(): void
    {
        $this->useRealAgenda();

        Http::fake([
            'http://clinic.test/api/pacientes?numero_documento=90000001' => Http::response([
                'data' => ['id' => 1, 'tipo_documento' => 'DNI', 'numero_documento' => '90000001', 'nombres' => 'Paciente', 'apellidos' => 'Prueba E2E'],
            ]),
            'http://clinic.test/api/pacientes/1/citas' => Http::response([
                'data' => [$this->appointment(5, 'Limpieza dental', '2026-09-10', '16', 'PENDIENTE')],
            ]),
            'http://clinic.test/api/citas/5/reprogramar' => Http::response(['message' => 'conflict'], 409),
        ]);

        $start = $this->postJson('/api/chat', [
            'userIdentifier' => 'mgmt-user',
            'message' => 'reprogramar mi cita',
        ]);
        $conversationId = $start->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '90000001',
        ]);
        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '2026-09-20',
        ]);

        $conflict = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'mgmt-user',
            'message' => '18:30',
        ]);

        // HTTP 200 con mensaje de conflicto: nunca un HTTP 500.
        $conflict->assertOk()->assertJsonPath('message', 'Esa hora dejó de estar disponible antes de registrarse. ¿Qué otra hora deseas intentar?');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('reschedule_time', $conversation->getContextValue('awaiting'));
    }
}
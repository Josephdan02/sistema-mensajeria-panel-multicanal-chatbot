<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\MockAppointment;
use App\Services\External\AI\AIServiceInterface;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\External\Agenda\MockAgendaService;
use App\Services\Intent\RuleBasedIntentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_json(): void
    {
        $this->getJson('/api/chat/health')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_message_endpoint_rejects_empty_messages(): void
    {
        $this->postJson('/api/chat', [
            'userIdentifier' => 'test-user-001',
            'message' => '',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_conversation_id_with_matching_user_identifier_continues_normally(): void
    {
        $conversation = Conversation::create(['user_identifier' => 'owner-user']);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'owner-user',
            'message' => 'Hola',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation_id', $conversation->id);
    }

    public function test_different_user_identifier_cannot_access_conversation(): void
    {
        $conversation = Conversation::create([
            'user_identifier' => 'conversation-owner',
            'context' => ['awaiting' => 'appointment_time', 'slots' => ['service' => 'Limpieza dental']],
        ]);
        $messagesBefore = $conversation->messages()->count();
        $contextBefore = $conversation->context;

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'different-user',
            'message' => '16:00',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $conversation->refresh();
        $this->assertSame($messagesBefore, $conversation->messages()->count());
        $this->assertEquals($contextBefore, $conversation->context);
    }

    public function test_foreign_user_cannot_confirm_cancel_or_reschedule_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'protected-owner',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25',
        ]);
        $conversationId = $response->json('data.conversation_id');
        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'protected-owner',
            'message' => '16:00',
        ]);
        $conversation = Conversation::findOrFail($conversationId);
        $appointmentId = $conversation->getContextValue('slots.appointment_id');

        foreach (['sí', 'no', 'reprogramar mi cita'] as $message) {
            $this->postJson('/api/chat', [
                'conversation_id' => $conversationId,
                'userIdentifier' => 'foreign-user',
                'message' => $message,
            ])->assertStatus(403);
        }

        $this->assertDatabaseHas('mock_appointments', [
            'id' => $appointmentId,
            'user_identifier' => 'protected-owner',
            'status' => 'pending_confirmation',
        ]);
        $this->assertSame('appointment_confirmation', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));
    }

    public function test_nonexistent_conversation_id_remains_a_validation_error(): void
    {
        $this->postJson('/api/chat', [
            'conversation_id' => 999999999,
            'userIdentifier' => 'missing-conversation-user',
            'message' => 'Hola',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('conversation_id');
    }

    public function test_only_user_identifier_still_creates_a_new_conversation(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'new-conversation-user',
            'message' => 'Hola',
        ])->assertOk();

        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('data.conversation_id'),
            'user_identifier' => 'new-conversation-user',
        ]);
    }

    public function test_conversation_id_requires_user_identifier(): void
    {
        $conversation = Conversation::create(['user_identifier' => 'stored-owner']);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'message' => 'Hola',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_identifier');
    }

    public function test_user_can_complete_mock_appointment_flow(): void
    {
        $first = $this->postJson('/api/chat', [
            'userIdentifier' => 'test-user-001',
            'message' => 'Quiero sacar una cita',
        ])->assertOk();

        $conversationId = $first->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'test-user-001',
            'message' => 'Limpieza dental',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'test-user-001',
            'message' => '2026-08-20',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'test-user-001',
            'message' => '10:30',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intent', 'request_appointment')
            ->assertJsonFragment(['conversation_id' => $conversationId]);

        $this->postJson('/api/chat', [
            'userIdentifier' => 'test-user-001',
            'message' => 'consultar cita',
        ])
            ->assertOk()
            ->assertJsonFragment(['intent' => 'check_appointment']);
    }

    public function test_greeting_unknown_and_information_intents_are_detected(): void
    {
        $this->postJson('/api/chat', ['userIdentifier' => 'greeting-user', 'message' => 'Hola'])
            ->assertJsonFragment(['intent' => 'greeting']);

        $this->postJson('/api/chat', ['userIdentifier' => 'unknown-user', 'message' => 'xyz sin sentido'])
            ->assertJsonFragment(['intent' => 'unknown']);

        foreach ([
            'servicios' => 'services',
            'precios' => 'pricing',
            'ubicación' => 'location',
            'horarios de atención' => 'schedule',
            'hablar con una persona' => 'human_handoff',
        ] as $message => $intent) {
            $this->postJson('/api/chat', [
                'userIdentifier' => "{$intent}-user",
                'message' => $message,
            ])->assertJsonFragment(['intent' => $intent]);
        }
    }

    public function test_rule_based_detector_prioritizes_specific_intent_patterns(): void
    {
        $detector = new RuleBasedIntentDetector;
        $conversation = Conversation::make();

        $cases = [
            'Hola, quisiera sacar una cita para una limpieza dental' => 'request_appointment',
            'Quisiera sacar una cita para una limpieza dental' => 'request_appointment',
            'Necesito cancelar la cita que tengo' => 'cancel_appointment',
            'Quiero cancelar mi cita' => 'cancel_appointment',
            'Quiero cambiar mi cita para otro día' => 'reschedule_appointment',
            'Quiero reprogramar mi cita' => 'reschedule_appointment',
            '¿A qué hora tengo mi cita?' => 'check_appointment',
            '¿Cuál es mi cita?' => 'check_appointment',
            '¿Cuánto cuesta una limpieza dental?' => 'pricing',
            '¿Dónde están ubicados?' => 'location',
            '¿Atienden los domingos?' => 'schedule',
            '¿Cuál es su horario?' => 'schedule',
            'Hola' => 'greeting',
            'Buenos días' => 'greeting',
            'Quiero consultar mi cita' => 'check_appointment',
        ];

        foreach ($cases as $message => $expectedIntent) {
            $this->assertSame($expectedIntent, $detector->detect($message, $conversation)->value, $message);
        }
    }

    public function test_appointment_without_service_keeps_asking_for_service(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'service-required-user',
            'message' => 'Quiero sacar una cita',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('appointment_service', $conversation->getContextValue('awaiting'));
        $this->assertNull($conversation->getContextValue('slots.service'));
        $this->assertStringContainsString('¿Para qué servicio', $response->json('message'));
    }

    public function test_appointment_message_with_service_skips_service_question(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'service-in-message-user',
            'message' => 'Quiero sacar una cita para una limpieza dental',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('appointment_date', $conversation->getContextValue('awaiting'));
        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $this->assertStringContainsString('¿Qué fecha', $response->json('message'));
        $this->assertStringNotContainsString('¿Para qué servicio', $response->json('message'));
    }
    public function test_request_appointment_failure_returns_failure_message(): void
{
    $this->app->bind(AgendaServiceInterface::class, function () {
        return new class implements AgendaServiceInterface {
            public function checkAvailability(string $service, string $date): array
            {
                return [['time' => '16:00']];
            }

            public function getAppointment(string $userIdentifier): ?array
            {
                return null;
            }

            public function requestAppointment(
                string $userIdentifier,
                string $service,
                string $date,
                string $time
            ): array {
                throw new RuntimeException('Agenda service unavailable');
            }

            public function confirmAppointment(
                string $userIdentifier,
                int|string $appointmentId
            ): ?array {
                return null;
            }

            public function cancelAppointment(
                string $userIdentifier,
                int|string $appointmentId
            ): bool {
                return false;
            }

            public function rescheduleAppointment(
                string $userIdentifier,
                int|string $appointmentId,
                string $date,
                string $time
            ): ?array {
                return null;
            }

            public function appointmentsByPatient(int $patientId): array
            {
                return [];
            }
        };
    });

    $response = $this->postJson('/api/chat', [
        'message' => 'Quiero una cita para limpieza dental el 2026-08-29 a las 16:00',
        'userIdentifier' => 'test-agenda-failure-001',
    ]);

    $response->assertStatus(200);

$response->assertJsonPath(
    'message',
    'No pudimos registrar la solicitud de cita en este momento. Por favor, intenta nuevamente.'
);

$this->assertDatabaseCount('mock_appointments', 0);

$this->assertStringNotContainsString(
    '¿Confirmas la cita?',
    $response->json('message')
);

$conversation = Conversation::query()
    ->where('user_identifier', 'test-agenda-failure-001')
    ->latest('id')
    ->firstOrFail();

$this->assertNull(
    $conversation->context['awaiting'] ?? null
);
}

    public function test_greeting_with_appointment_service_skips_greeting_response(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'greeting-service-user',
            'message' => 'Hola, quisiera sacar una cita para una limpieza dental',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $this->assertSame('appointment_date', $conversation->getContextValue('awaiting'));
        $this->assertStringContainsString('¿Qué fecha', $response->json('message'));
    }

    public function test_pricing_message_stores_the_extracted_service(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'pricing-service-user',
            'message' => '¿Cuánto cuesta una extracción dental?',
        ])->assertJsonFragment(['intent' => 'pricing']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('Extracción dental', $conversation->getContextValue('slots.service'));
    }

    public function test_appointment_with_extracted_service_continues_with_date_and_time(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'complete-service-user',
            'message' => 'Quiero una cita para ortodoncia',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->assertSame('appointment_date', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'complete-service-user',
            'message' => '2026-08-25',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'complete-service-user',
            'message' => '10:30',
        ])->assertOk()->assertJsonFragment(['conversation_id' => $conversationId]);

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'complete-service-user',
            'service' => 'Ortodoncia',
            'date' => '2026-08-25',
            'time' => '10:30',
        ]);
    }

    public function test_appointment_message_with_service_and_date_skips_date_question(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'service-date-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $this->assertSame('2026-08-25', $conversation->getContextValue('slots.date'));
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));
        $this->assertStringContainsString('¿Cuál prefieres?', $response->json('message'));
    }

    public function test_relative_date_with_appointment_and_service_is_saved_as_iso_date(): void
    {
        $today = now()->startOfDay();
        $expectedDate = $today->copy()->addDay()->toDateString();

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'relative-date-user',
            'message' => 'Quiero una cita para una limpieza dental mañana',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $this->assertSame($expectedDate, $conversation->getContextValue('slots.date'));
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));
    }
     
    public function test_weekday_date_with_appointment_and_service_is_saved_and_continues_to_time(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'weekday-date-user',
            'message' => 'Quiero una cita para una limpieza dental el viernes',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $conversation->getContextValue('slots.date'));
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));
    }
    
    public function test_appointment_message_with_service_date_and_time_is_completed(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'inline-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25 a las 10:30',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'inline-time-user',
            'service' => 'Limpieza dental',
            'date' => '2026-08-25',
            'time' => '10:30',
            'status' => 'pending_confirmation',
        ]);

        $conversationId = $response->json('data.conversation_id');
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('appointment_confirmation', $conversation->getContextValue('awaiting'));
        $this->assertNotNull($conversation->getContextValue('slots.appointment_id'));

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'inline-time-user',
            'message' => 'sí',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'inline-time-user',
            'status' => 'confirmed',
        ]);
    }

    public function test_appointment_confirmation_flow_confirms_and_preserves_intent(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'confirmation-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ])->assertJsonFragment(['intent' => 'request_appointment']);
        $conversationId = $response->json('data.conversation_id');

        $conversation = Conversation::findOrFail($conversationId);
        $appointmentId = $conversation->getContextValue('slots.appointment_id');
        $this->assertSame('appointment_confirmation', $conversation->getContextValue('awaiting'));
        $this->assertNotNull($appointmentId);
        $this->assertDatabaseHas('mock_appointments', [
            'id' => $appointmentId,
            'status' => 'pending_confirmation',
        ]);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'confirmation-user',
            'message' => 'confirmar',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->assertDatabaseHas('mock_appointments', [
            'id' => $appointmentId,
            'status' => 'confirmed',
        ]);
        $this->assertSame([], Conversation::findOrFail($conversationId)->context);
    }

    public function test_negative_appointment_confirmation_cancels_the_request(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'negative-confirmation-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');
        $appointmentId = Conversation::findOrFail($conversationId)->getContextValue('slots.appointment_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'negative-confirmation-user',
            'message' => 'no',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->assertDatabaseMissing('mock_appointments', ['id' => $appointmentId]);
        $this->assertSame([], Conversation::findOrFail($conversationId)->context);
    }

    public function test_ambiguous_appointment_confirmation_keeps_waiting(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'ambiguous-confirmation-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'ambiguous-confirmation-user',
            'message' => 'tal vez',
        ])->assertJsonPath('data.intent', 'request_appointment')
            ->assertJsonPath('message', 'Responde sí para confirmar la cita o no para cancelarla.');

        $this->assertSame('appointment_confirmation', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));
    }

    public function test_confirmation_of_missing_appointment_returns_controlled_response(): void
    {
        $conversation = Conversation::create([
            'user_identifier' => 'missing-confirmation-user',
            'context' => [
                'awaiting' => 'appointment_confirmation',
                'slots' => [
                    'intent' => 'request_appointment',
                    'appointment_id' => 999999,
                ],
            ],
        ]);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'missing-confirmation-user',
            'message' => 'sí',
        ])->assertJsonPath('data.intent', 'request_appointment')
            ->assertJsonPath('message', 'No encontramos ninguna cita registrada a tu nombre. ¿Deseas agendar una nueva?');
    }

    public function test_pending_confirmation_can_be_interrupted_to_consult_an_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-check-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'interrupt-check-user',
            'message' => 'quiero consultar mi cita',
        ])->assertJsonPath('data.intent', 'check_appointment')
            ->assertJsonPath('message', 'Tienes una cita de "Limpieza dental" el 2026-08-25 a las 16:00 (estado: pending_confirmation).');
    }

    public function test_pending_confirmation_can_be_interrupted_to_cancel_the_request(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-cancel-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');
        $appointmentId = Conversation::findOrFail($conversationId)->getContextValue('slots.appointment_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'interrupt-cancel-user',
            'message' => 'quiero cancelar mi cita',
        ])->assertJsonPath('data.intent', 'cancel_appointment');

        $this->assertDatabaseMissing('mock_appointments', ['id' => $appointmentId]);
        $this->assertSame([], Conversation::findOrFail($conversationId)->context);
    }

    public function test_pending_confirmation_can_be_interrupted_to_reschedule(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-reschedule-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'interrupt-reschedule-user',
            'message' => 'quiero reprogramar mi cita',
        ])->assertJsonPath('data.intent', 'reschedule_appointment');

        $this->assertSame('reschedule_date', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));
    }

    public function test_pending_confirmation_can_be_interrupted_by_a_new_appointment_request(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-new-request-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25 a las 16:00',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'interrupt-new-request-user',
            'message' => 'quiero una cita',
        ])->assertJsonPath('data.intent', 'request_appointment');

        $this->assertSame('appointment_service', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));
    }

    public function test_explicit_operations_interrupt_date_and_time_slots(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-slots-user',
            'message' => 'Quiero una cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'interrupt-slots-user', 'message' => 'Limpieza dental']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'interrupt-slots-user', 'message' => 'consultar mi cita'])
            ->assertJsonPath('data.intent', 'check_appointment');

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'interrupt-time-slot-user',
            'message' => 'Quiero una cita para limpieza dental el 2026-08-25',
        ]);
        $timeConversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $timeConversationId, 'userIdentifier' => 'interrupt-time-slot-user', 'message' => 'reprogramar mi cita'])
            ->assertJsonPath('data.intent', 'reschedule_appointment');
    }

    public function test_invalid_time_does_not_create_an_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25 a las 25:00',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $this->assertStringContainsString('La hora no es válida', $response->json('message'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'invalid-time-user',
        ]);
    }

    public function test_selected_time_must_be_available_before_creating_an_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'unavailable-inline-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25 a las 13:00',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $this->assertStringContainsString('no está disponible', $response->json('message'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'unavailable-inline-time-user',
        ]);
    }

    public function test_time_selected_in_a_later_message_is_validated_and_persisted(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'later-time-user',
            'message' => 'Quiero una cita para ortodoncia',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'later-time-user', 'message' => '2026-08-25']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'later-time-user', 'message' => '10:30'])
            ->assertOk()
            ->assertJsonPath('data.intent', 'request_appointment');
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'later-time-user', 'message' => 'sí'])
            ->assertJsonPath('data.intent', 'request_appointment');

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'later-time-user',
            'time' => '10:30',
        ]);
    }

    public function test_pending_appointment_date_response_keeps_request_intent(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'pending-date-intent-user',
            'message' => 'Quiero una cita para limpieza dental',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'pending-date-intent-user',
            'message' => '2026-08-22',
        ])->assertJsonPath('data.intent', 'request_appointment');
    }

    public function test_conversation_without_stored_context_can_remain_unknown(): void
    {
        $this->postJson('/api/chat', [
            'userIdentifier' => 'unknown-context-user',
            'message' => 'mensaje sin intención conocida',
        ])
            ->assertJsonPath('data.intent', 'unknown')
            ->assertJsonPath('success', true);
    }

    public function test_missing_time_keeps_waiting_for_a_available_time(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'missing-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversationId = $response->json('data.conversation_id');
        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'missing-time-user',
            'message' => 'Quiero una cita para una limpieza dental mañana',
        ]);

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));
        $this->assertSame('Limpieza dental', $conversation->getContextValue('slots.service'));
        $expectedDate = now()->startOfDay()->addDay()->toDateString();
        $this->assertSame($expectedDate, $conversation->getContextValue('slots.date'));
        $this->assertStringContainsString('¿Cuál prefieres?', $response->json('message'));
        $this->assertStringNotContainsString('La hora no es válida', $response->json('message'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'missing-time-user',
        ]);
    }

    public function test_invalid_time_keeps_the_existing_error_response(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-later-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'invalid-later-time-user',
            'message' => '25:90',
        ])->assertJsonPath('message', 'La hora no es válida. Ingresa una hora en formato HH:MM, por ejemplo: 10:30.');
    }

    public function test_valid_time_continues_and_registers_the_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'valid-later-time-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-08-25',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'valid-later-time-user',
            'message' => '16:00',
        ])->assertOk();

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'valid-later-time-user',
            'service' => 'Limpieza dental',
            'date' => '2026-08-25',
            'time' => '16:00',
        ]);
    }

    public function test_invalid_date_in_initial_appointment_message_is_not_saved(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-inline-date-user',
            'message' => 'Quiero una cita para una limpieza dental el 2026-02-31',
        ])->assertJsonFragment(['intent' => 'request_appointment']);

        $conversation = Conversation::findOrFail($response->json('data.conversation_id'));

        $this->assertNull($conversation->getContextValue('slots.date'));
        $this->assertSame('appointment_date', $conversation->getContextValue('awaiting'));
        $this->assertStringContainsString('La fecha no es válida', $response->json('message'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'invalid-inline-date-user',
        ]);
    }

    public function test_availability_is_an_independent_flow_without_creating_an_appointment(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'availability-user',
            'message' => '¿Qué horarios tienen para limpieza dental?',
        ])->assertJsonFragment(['intent' => 'availability']);

        $conversationId = $response->json('data.conversation_id');
        $this->assertStringContainsString('fecha', $response->json('message'));

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'availability-user',
            'message' => '2026-08-20',
        ])
            ->assertJsonFragment(['intent' => 'availability'])
            ->assertJsonPath('message', 'Para el 2026-08-20 tenemos disponibilidad a las: 09:00, 10:30, 16:00. ¿Cuál prefieres?');

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversationId,
            'message' => 'Tu solicitud de cita',
        ]);

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame([], $conversation->context);
    }

    public function test_unavailable_appointment_time_is_rejected_and_available_time_is_accepted(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'time-user',
            'message' => 'Quiero sacar una cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'time-user', 'message' => 'Limpieza dental']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'time-user', 'message' => '2026-08-20']);

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('appointment_time', $conversation->getContextValue('awaiting'));

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'time-user', 'message' => '13:00'])
            ->assertJsonPath('message', 'El horario 13:00 no está disponible. Puedes elegir entre: 09:00, 10:30, 16:00.');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('10:30', $conversation->getContextValue('slots.available_slots.1.time'));
        $this->assertNull($conversation->getContextValue('slots.time'));

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'time-user', 'message' => '10:30'])
            ->assertOk()
            ->assertJsonFragment(['conversation_id' => $conversationId]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'message' => '10:30',
        ]);
    }

    public function test_invalid_appointment_date_does_not_check_availability(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-date-user',
            'message' => 'Quiero sacar una cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-date-user', 'message' => 'Limpieza dental']);

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-date-user', 'message' => '2026-02-31'])
            ->assertJsonPath('message', 'La fecha no es válida. Ingresa una fecha real en formato YYYY-MM-DD, por ejemplo: 2026-08-25.');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('appointment_date', $conversation->getContextValue('awaiting'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'invalid-date-user',
        ]);
    }

    public function test_valid_appointment_date_is_accepted(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'valid-date-user',
            'message' => 'Quiero sacar una cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'valid-date-user', 'message' => 'Limpieza dental']);

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'valid-date-user', 'message' => '2026-08-25'])
            ->assertJsonPath('message', 'Para el 2026-08-25 tenemos disponibilidad a las: 09:00, 10:30, 16:00. ¿Cuál prefieres?');
    }

    public function test_invalid_availability_date_does_not_check_availability(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-availability-date-user',
            'message' => '¿Qué horarios tienen para limpieza dental?',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-availability-date-user', 'message' => '2026-13-01'])
            ->assertJsonPath('message', 'La fecha no es válida. Ingresa una fecha real en formato YYYY-MM-DD, por ejemplo: 2026-08-25.');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertSame('availability_date', $conversation->getContextValue('awaiting'));
        $this->assertDatabaseMissing('mock_appointments', [
            'user_identifier' => 'invalid-availability-date-user',
        ]);
    }

    public function test_cancel_and_reschedule_appointment_flows_use_the_mock_agenda(): void
    {
        $this->createAppointment('manage-user');

        $this->postJson('/api/chat', [
            'userIdentifier' => 'manage-user',
            'message' => 'cancelar cita',
        ])->assertJsonFragment(['intent' => 'cancel_appointment']);

        $this->createAppointment('manage-user');

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'manage-user',
            'message' => 'reprogramar cita',
        ])->assertJsonFragment(['intent' => 'reschedule_appointment']);
        $conversationId = $response->json('data.conversation_id');

        $conversation = Conversation::findOrFail($conversationId);
        $this->assertNotNull($conversation->getContextValue('slots.appointment_id'));

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'manage-user', 'message' => '2026-08-25']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'manage-user', 'message' => '16:00'])
            ->assertJsonFragment(['conversation_id' => $conversationId]);
    }

    public function test_invalid_reschedule_date_does_not_update_the_appointment(): void
    {
        $this->createAppointment('invalid-reschedule-date-user');
        $appointmentBefore = MockAppointment::query()
            ->where('user_identifier', 'invalid-reschedule-date-user')
            ->firstOrFail();

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-reschedule-date-user',
            'message' => 'reprogramar cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-reschedule-date-user', 'message' => '2026-02-30'])
            ->assertJsonPath('message', 'La fecha no es válida. Ingresa una fecha real en formato YYYY-MM-DD, por ejemplo: 2026-08-25.');

        $appointmentAfter = MockAppointment::query()->findOrFail($appointmentBefore->id);
        $this->assertSame($appointmentBefore->date, $appointmentAfter->date);
        $this->assertSame('reschedule_date', Conversation::findOrFail($conversationId)->getContextValue('awaiting'));
    }

    public function test_reschedule_with_available_time_updates_the_appointment(): void
    {
        $this->createAppointment('valid-reschedule-time-user');
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'valid-reschedule-time-user',
            'message' => 'reprogramar cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'valid-reschedule-time-user', 'message' => '2026-08-25']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'valid-reschedule-time-user', 'message' => '4 pm'])
            ->assertJsonFragment(['intent' => 'reschedule_appointment']);

        $this->assertDatabaseHas('mock_appointments', [
            'user_identifier' => 'valid-reschedule-time-user',
            'date' => '2026-08-25',
            'time' => '16:00',
        ]);
    }

    public function test_invalid_reschedule_time_does_not_update_the_appointment(): void
    {
        $this->createAppointment('invalid-reschedule-time-user');
        $appointment = MockAppointment::query()->where('user_identifier', 'invalid-reschedule-time-user')->firstOrFail();
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'invalid-reschedule-time-user',
            'message' => 'reprogramar cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-reschedule-time-user', 'message' => '2026-08-25']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'invalid-reschedule-time-user', 'message' => '25:90'])
            ->assertJsonPath('message', 'La hora no es válida. Ingresa una hora en formato HH:MM, por ejemplo: 10:30.');

        $this->assertSame($appointment->date, MockAppointment::findOrFail($appointment->id)->date);
    }

    public function test_unavailable_reschedule_time_does_not_update_the_appointment(): void
    {
        $this->createAppointment('unavailable-reschedule-time-user');
        $appointment = MockAppointment::query()->where('user_identifier', 'unavailable-reschedule-time-user')->firstOrFail();
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'unavailable-reschedule-time-user',
            'message' => 'reprogramar cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'unavailable-reschedule-time-user', 'message' => '2026-08-25']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => 'unavailable-reschedule-time-user', 'message' => '13:00'])
            ->assertJsonPath('message', 'El horario 13:00 no está disponible. Puedes elegir entre: 09:00, 10:30, 16:00.');

        $this->assertSame($appointment->time, MockAppointment::findOrFail($appointment->id)->time);
    }

    public function test_reschedule_without_an_appointment_returns_a_controlled_response(): void
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'missing-reschedule-user',
            'message' => 'reprogramar cita',
        ]);

        $response->assertJsonPath('message', 'No encontré una cita para reprogramar.')
            ->assertJsonPath('data.intent', 'reschedule_appointment');
    }

    public function test_cancel_phrases_are_not_classified_as_checking_an_appointment(): void
    {
        foreach (['Quiero cancelar mi cita', 'Deseo cancelar mi cita'] as $index => $message) {
            $this->postJson('/api/chat', [
                'userIdentifier' => "cancel-phrase-user-{$index}",
                'message' => $message,
            ])->assertJsonFragment(['intent' => 'cancel_appointment']);
        }
    }

    public function test_reschedule_phrases_are_not_classified_as_checking_an_appointment(): void
    {
        foreach (['Quiero reprogramar mi cita', 'Deseo reprogramar mi cita'] as $index => $message) {
            $this->postJson('/api/chat', [
                'userIdentifier' => "reschedule-phrase-user-{$index}",
                'message' => $message,
            ])->assertJsonFragment(['intent' => 'reschedule_appointment']);
        }
    }

    public function test_gemini_failure_uses_the_local_fallback(): void
    {
        $this->mock(AIServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('generateReply')
                ->once()
                ->andThrow(new RuntimeException('Gemini unavailable'));
        });

        $this->postJson('/api/chat', [
            'userIdentifier' => 'fallback-user',
            'message' => 'mensaje que no coincide',
        ])
            ->assertOk()
            ->assertJsonFragment(['intent' => 'unknown'])
            ->assertJsonPath('message', 'Disculpa, no logré entender tu mensaje. ¿Podrías reformularlo? También puedo ponerte en contacto con una persona si lo prefieres.');
    }

    public function test_mock_agenda_persists_appointments_between_service_instances(): void
    {
        $creator = new MockAgendaService;
        $created = $creator->requestAppointment(
            'persistent-user',
            'Limpieza dental',
            '2026-08-20',
            '10:30',
        );

        $reader = new MockAgendaService;
        $this->assertSame($created['id'], $reader->getAppointment('persistent-user')['id']);

        $rescheduled = $reader->rescheduleAppointment(
            'persistent-user',
            $created['id'],
            '2026-08-25',
            '16:00',
        );

        $this->assertSame('Limpieza dental', $rescheduled['service']);
        $this->assertSame('pending_confirmation', $rescheduled['status']);
        $this->assertSame('2026-08-25', $rescheduled['date']);
        $this->assertSame('16:00', $rescheduled['time']);

        $canceller = new MockAgendaService;
        $this->assertTrue($canceller->cancelAppointment('persistent-user', $created['id']));
        $this->assertNull($creator->getAppointment('persistent-user'));
        $this->assertDatabaseMissing('mock_appointments', [
            'id' => $created['id'],
        ]);
    }

    public function test_multiple_appointments_get_appointment_selects_one_and_cancel_affects_only_selected(): void
    {
        $agenda = new MockAgendaService;

        $first = $agenda->requestAppointment('multi-user', 'Limpieza dental', '2026-08-20', '09:00');
        $second = $agenda->requestAppointment('multi-user', 'Ortodoncia', '2026-08-21', '10:30');
        $otherUser = $agenda->requestAppointment('other-user', 'Extracción dental', '2026-08-22', '16:00');

        $selected = $agenda->getAppointment('multi-user');

        $this->assertNotNull($selected);
        $this->assertContains($selected['id'], [$first['id'], $second['id']]);

        $this->postJson('/api/chat', [
            'userIdentifier' => 'multi-user',
            'message' => 'cancelar cita',
        ])->assertJsonFragment(['intent' => 'cancel_appointment']);

        $remainingId = $selected['id'] === $first['id'] ? $second['id'] : $first['id'];

        $this->assertDatabaseMissing('mock_appointments', [
            'id' => $selected['id'],
        ]);
        $this->assertDatabaseHas('mock_appointments', [
            'id' => $remainingId,
            'user_identifier' => 'multi-user',
        ]);
        $this->assertDatabaseHas('mock_appointments', [
            'id' => $otherUser['id'],
            'user_identifier' => 'other-user',
            'date' => '2026-08-22',
            'time' => '16:00',
        ]);
    }

    public function test_multiple_appointments_reschedule_affects_only_the_selected_appointment(): void
    {
        $agenda = new MockAgendaService;

        $first = $agenda->requestAppointment('multi-reschedule-user', 'Limpieza dental', '2026-08-20', '09:00');
        $second = $agenda->requestAppointment('multi-reschedule-user', 'Ortodoncia', '2026-08-21', '10:30');
        $otherUser = $agenda->requestAppointment('other-reschedule-user', 'Extracción dental', '2026-08-22', '16:00');

        $selected = $agenda->getAppointment('multi-reschedule-user');

        $this->assertNotNull($selected);
        $this->assertContains($selected['id'], [$first['id'], $second['id']]);

        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'multi-reschedule-user',
            'message' => 'reprogramar cita',
        ])->assertJsonFragment(['intent' => 'reschedule_appointment']);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'multi-reschedule-user',
            'message' => '2026-08-25',
        ]);
        $this->postJson('/api/chat', [
            'conversation_id' => $conversationId,
            'userIdentifier' => 'multi-reschedule-user',
            'message' => '16:00',
        ])->assertJsonFragment(['intent' => 'reschedule_appointment']);

        $notSelectedId = $selected['id'] === $first['id'] ? $second['id'] : $first['id'];
        $notSelectedOriginal = $selected['id'] === $first['id'] ? $second : $first;

        $this->assertDatabaseHas('mock_appointments', [
            'id' => $selected['id'],
            'user_identifier' => 'multi-reschedule-user',
            'date' => '2026-08-25',
            'time' => '16:00',
        ]);
        $this->assertDatabaseHas('mock_appointments', [
            'id' => $notSelectedId,
            'user_identifier' => 'multi-reschedule-user',
            'date' => $notSelectedOriginal['date'],
            'time' => $notSelectedOriginal['time'],
        ]);
        $this->assertDatabaseHas('mock_appointments', [
            'id' => $otherUser['id'],
            'user_identifier' => 'other-reschedule-user',
            'date' => '2026-08-22',
            'time' => '16:00',
        ]);
    }

    private function createAppointment(string $userIdentifier): int
    {
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => $userIdentifier,
            'message' => 'Quiero sacar una cita',
        ]);
        $conversationId = $response->json('data.conversation_id');

        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => $userIdentifier, 'message' => 'Limpieza dental']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => $userIdentifier, 'message' => '2026-08-20']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => $userIdentifier, 'message' => '10:30']);
        $this->postJson('/api/chat', ['conversation_id' => $conversationId, 'userIdentifier' => $userIdentifier, 'message' => 'sí']);

        return $conversationId;
    }
}

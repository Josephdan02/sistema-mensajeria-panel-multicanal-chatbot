<?php

namespace Tests\Feature\Telegram;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chatbot\ChatbotService;
use App\Services\External\Agenda\AgendaServiceInterface;
use Illuminate\Support\Facades\Http;
use Mockery;

class TelegramWebhookTest extends TelegramTestCase
{
    public function test_webhook_rejects_an_invalid_secret(): void
    {
        Http::fake();

        $this->postWebhook($this->update(12345, 'hola'), 'wrong-secret')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Conversation::count());
        Http::assertNothingSent();
    }

    public function test_valid_text_creates_telegram_conversation_and_sends_reply(): void
    {
        Http::fake();

        $this->postWebhook($this->update(12345, 'hola'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $conversation = Conversation::query()
            ->where('channel', 'telegram')
            ->where('user_identifier', '12345')
            ->firstOrFail();

        $this->assertSame(ConversationState::IDLE, $conversation->state);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => 'hola',
        ]);
        $this->assertTelegramChatSent('12345');
    }

    public function test_start_is_normalized_to_hola_before_chatbot_processing(): void
    {
        Http::fake();

        $this->postWebhook($this->update(12346, '/start', 1002))->assertOk();

        $this->assertDatabaseHas('messages', [
            'sender' => Message::SENDER_USER,
            'message' => 'hola',
        ]);
    }

    public function test_second_update_reuses_the_same_conversation(): void
    {
        Http::fake();

        $this->postWebhook($this->update(12347, 'hola', 1003))->assertOk();
        $this->postWebhook($this->update(12347, 'servicios', 1004))->assertOk();

        $this->assertSame(1, Conversation::where('channel', 'telegram')->count());
        $this->assertSame(4, Message::whereHas('conversation', function ($query): void {
            $query->where('channel', 'telegram');
        })->count());
    }

    public function test_closed_conversation_reopens_for_the_bot_and_clears_stale_agent_metadata(): void
    {
        Http::fake();
        $agent = User::factory()->create();
        $conversation = Conversation::create([
            'user_identifier' => '123471',
            'channel' => 'telegram',
            'state' => ConversationState::CLOSED,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now()->subHour(),
            'closed_at' => now()->subMinutes(30),
            'closed_by' => $agent->id,
        ]);
        $previous = Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => 'La atención ha finalizado.',
            'agent_id' => $agent->id,
            'read_at' => now(),
        ]);

        $this->postWebhook($this->update(123471, 'hola', 10041))->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationState::IDLE, $conversation->state);
        $this->assertNull($conversation->assigned_agent_id);
        $this->assertNull($conversation->assigned_at);
        $this->assertNull($conversation->closed_at);
        $this->assertNull($conversation->closed_by);
        $this->assertTrue($conversation->messages()->whereKey($previous->id)->exists());
        $this->assertSame(3, $conversation->messages()->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertTelegramChatSent('123471');
    }

    public function test_non_text_message_receives_helpful_response(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postWebhook([
            'update_id' => 1005,
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => 12348],
                'photo' => [['file_id' => 'photo-id']],
            ],
        ])->assertOk();

        $this->assertSame(1, Conversation::where('channel', 'telegram')->count());
        $this->assertSame(1, Conversation::where('user_identifier', '12348')->count());
        Http::assertSentCount(1);
    }

    public function test_non_text_message_during_handoff_is_recorded_without_bot_reply(): void
    {
        Http::fake();
        $conversation = Conversation::create([
            'user_identifier' => '123481',
            'channel' => 'telegram',
            'state' => ConversationState::ASSIGNED,
        ]);

        $this->postWebhook([
            'update_id' => 10051,
            'message' => [
                'message_id' => 3,
                'chat' => ['id' => 123481],
                'photo' => [['file_id' => 'photo-id']],
            ],
        ])->assertOk();

        $conversation->refresh();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertSame(0, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
        $this->assertSame(1, $conversation->unreadMessagesCount());
        $this->assertTelegramSentCount(0);
    }

    public function test_non_text_message_during_handoff_describes_the_attachment_type(): void
    {
        Http::fake();
        $conversation = Conversation::create([
            'user_identifier' => '123482',
            'channel' => 'telegram',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->postWebhook([
            'update_id' => 10052,
            'message' => [
                'message_id' => 4,
                'chat' => ['id' => 123482],
                'document' => ['file_id' => 'doc-id'],
            ],
        ])->assertOk();

        $message = $conversation->messages()->where('sender', Message::SENDER_USER)->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('documento', $message->message);
        $this->assertTelegramSentCount(0);
    }

    public function test_duplicate_update_id_is_processed_only_once(): void
    {
        Http::fake();
        $payload = $this->update(12349, 'hola', 1006);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, Conversation::where('channel', 'telegram')->count());
        $this->assertSame(1, Message::where('sender', Message::SENDER_USER)->count());
        $this->assertTelegramSentCount(1);
    }

    public function test_handoff_conversation_does_not_send_bot_reply(): void
    {
        Http::fake();
        $conversation = Conversation::create([
            'user_identifier' => '12350',
            'channel' => 'telegram',
            'state' => ConversationState::ASSIGNED,
        ]);

        $this->postWebhook($this->update(12350, 'Necesito ayuda', 1007))->assertOk();

        $conversation->refresh();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame(1, $conversation->unreadMessagesCount());
        $this->assertTelegramSentCount(0);
    }

    public function test_new_message_in_telegram_handoff_is_recorded_without_repeating_bot_notice(): void
    {
        Http::fake();
        $conversation = Conversation::create([
            'user_identifier' => '123501',
            'channel' => 'telegram',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);

        $this->postWebhook($this->update(123501, '¿Hay novedades?', 10071))->assertOk();

        $conversation->refresh();
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame('¿Hay novedades?', $conversation->messages()->first()->message);
        $this->assertTelegramSentCount(0);
    }

    public function test_telegram_and_whatsapp_conversations_coexist_for_same_identifier(): void
    {
        Http::fake();
        Conversation::create([
            'user_identifier' => '12351',
            'channel' => 'whatsapp',
        ]);

        $this->postWebhook($this->update(12351, 'hola', 1008))->assertOk();

        $this->assertSame(1, Conversation::where('channel', 'whatsapp')->count());
        $this->assertSame(1, Conversation::where('channel', 'telegram')->count());
    }

    public function test_inbound_delegates_text_to_chatbot_service(): void
    {
        Http::fake();
        $chatbot = Mockery::mock(ChatbotService::class);
        $chatbot->shouldReceive('handle')->once()
            ->withArgs(function (Conversation $conversation, string $message): bool {
                return $conversation->channel === 'telegram'
                    && $conversation->user_identifier === '12352'
                    && $message === 'mensaje delegado';
            })
            ->andReturn([
                'conversation' => Conversation::first(),
                'message' => 'Respuesta delegada',
                'intent' => 'unknown',
                'handled_by' => 'bot',
            ]);
        $this->app->instance(ChatbotService::class, $chatbot);

        $this->postWebhook($this->update(12352, 'mensaje delegado', 1009))->assertOk();

        $this->assertTelegramSent('12352', 'Respuesta delegada');
    }

    public function test_appointment_request_is_transferred_without_consulting_or_creating_an_appointment(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'clinic.test/*' => Http::response(['data' => ['available' => true]], 200),
        ]);

        $this->postWebhook($this->update(12353, 'Quiero agendar una cita', 1010))
            ->assertOk();

        $conversation = Conversation::query()
            ->where('channel', 'telegram')
            ->where('user_identifier', '12353')
            ->firstOrFail();

        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => 'Quiero agendar una cita',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_BOT,
        ]);
        Http::assertSentCount(1);
    }

    public function test_reschedule_request_is_transferred_without_starting_automatic_flow(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'clinic.test/*' => Http::response(['data' => ['available' => true]], 200),
        ]);

        $agenda = Mockery::mock(AgendaServiceInterface::class);
        $agenda->shouldNotReceive('getAppointment');
        $agenda->shouldNotReceive('checkAvailability');
        $agenda->shouldNotReceive('rescheduleAppointment');
        $this->app->instance(AgendaServiceInterface::class, $agenda);

        $this->postWebhook($this->update(12355, 'hola, quiero reprogramar cita', 3012))
            ->assertOk();

        $conversation = Conversation::query()
            ->where('channel', 'telegram')
            ->where('user_identifier', '12355')
            ->firstOrFail();

        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);
        $this->assertNull($conversation->getContextValue('awaiting'));
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => 'hola, quiero reprogramar cita',
            'intent' => 'reschedule_appointment',
        ]);
        $this->assertStringNotContainsString(
            'dni',
            strtolower((string) $conversation->messages()->where('sender', Message::SENDER_BOT)->value('message')),
        );
        $this->assertStringNotContainsString(
            'fecha',
            strtolower((string) $conversation->messages()->where('sender', Message::SENDER_BOT)->value('message')),
        );
        $this->assertStringNotContainsString(
            'hora',
            strtolower((string) $conversation->messages()->where('sender', Message::SENDER_BOT)->value('message')),
        );
        $this->assertTelegramSentCount(1);
    }

    public function test_human_handoff_interrupts_pending_reschedule_without_date_error(): void
    {
        Http::fake('api.telegram.org/*', Http::response(['ok' => true], 200));
        $conversation = Conversation::create([
            'user_identifier' => '12354',
            'channel' => 'telegram',
            'state' => ConversationState::IDLE,
            'context' => [
                'awaiting' => 'reschedule_date',
                'slots' => ['service' => 'Limpieza dental'],
            ],
        ]);

        $this->postWebhook($this->update(12354, 'quiero hablar con algun miembro del personal', 3011))
            ->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);
        $this->assertNull($conversation->getContextValue('awaiting'));
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
        $this->assertTelegramSentCount(0);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'message' => 'La fecha no es válida.',
        ]);
    }

    private function assertTelegramSent(string $chatId, string $text): void
    {
        Http::assertSent(function ($request) use ($chatId, $text): bool {
            return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
                && $request['chat_id'] === $chatId
                && $request['text'] === $text;
        });
    }

    private function assertTelegramChatSent(string $chatId): void
    {
        Http::assertSent(function ($request) use ($chatId): bool {
            return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
                && $request['chat_id'] === $chatId
                && is_string($request['text'])
                && $request['text'] !== '';
        });
    }

    /**
     * Cuenta los mensajes enviados por el bot (no cuenta las llamadas a
     * `getFile` ni las descargas de archivo usadas para recuperar adjuntos).
     */
    private function assertTelegramSentCount(int $count): void
    {
        $sent = Http::recorded(fn ($request): bool => str_contains(
            $request->url(),
            'api.telegram.org',
        ) && str_contains($request->url(), '/sendMessage'))->count();

        $this->assertSame($count, $sent);
    }
}

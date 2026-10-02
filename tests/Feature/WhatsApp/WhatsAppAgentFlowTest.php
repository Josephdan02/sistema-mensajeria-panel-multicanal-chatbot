<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;

/**
 * Ciclo completo WhatsApp: entrante -> bot -> agente (panel) -> salida por
 * WhatsApp -> liberación al bot. Verifica que la web no se toca.
 */
class WhatsAppAgentFlowTest extends WhatsAppTestCase
{
    public function test_full_cycle_incoming_agent_reply_and_release_to_bot(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $number = '549117770001';

        // 1) Entrante: el bot controla y responde por WhatsApp.
        $this->postWebhook($this->inboundTextPayload($number, 'hola', 'wamid.F1'))
            ->assertOk();

        $conversation = Conversation::query()
            ->where('channel', 'whatsapp')
            ->where('user_identifier', $number)
            ->firstOrFail();

        $this->assertSame(ConversationState::IDLE, $conversation->state);
        $this->assertWhatsAppSentCount(1);

        // 2) El agente toma la conversación desde el panel.
        $agent = $this->agent();

        $this->actingAs($agent)
            ->post(route('agent.conversations.take', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $this->assertSame(ConversationState::ASSIGNED, $conversation->refresh()->state);

        // 3) El cliente escribe de nuevo: persiste, el bot NO responde.
        $this->postWebhook($this->inboundTextPayload($number, 'Necesito una cita', 'wamid.F2'))
            ->assertOk();

        $conversation->refresh();
        $this->assertSame(ConversationState::ASSIGNED, $conversation->state);
        $this->assertSame(1, $conversation->unreadMessagesCount());
        $this->assertWhatsAppSentCount(1); // sigue siendo solo el del paso 1

        // 4) El agente responde desde el panel: el mensaje sale al número.
        $reply = '¡Hola! Soy tu asistente humano, ¿en qué te ayudo?';

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), ['message' => $reply])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();
        $this->assertSame(ConversationState::ATTENDING, $conversation->state);
        $this->assertWhatsAppSentCount(2);
        $this->assertWhatsAppSent($number, $reply);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => $reply,
        ]);

        // 5) El agente libera la conversación al bot.
        $this->actingAs($agent)
            ->post(route('agent.conversations.release', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $this->assertSame(ConversationState::IDLE, $conversation->refresh()->state);

        // 6) Siguiente entrante: el bot vuelve a responder por WhatsApp.
        $this->postWebhook($this->inboundTextPayload($number, 'gracias, adiós', 'wamid.F3'))
            ->assertOk();

        $this->assertSame(ConversationState::IDLE, $conversation->refresh()->state);
        $this->assertWhatsAppSentCount(3);
    }

    public function test_agent_reply_on_web_conversation_never_calls_whatsapp(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-paciente',
            'channel' => 'web',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);
        $this->customerMessage($conversation, 'Hola, necesito ayuda');

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), ['message' => '¿En qué te ayudo?'])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        // La web funciona igual que siempre: mensaje persistido, cero llamadas
        // a WhatsApp Cloud API.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => '¿En qué te ayudo?',
        ]);
        $this->assertWhatsAppSentCount(0);
    }

    public function test_incoming_web_message_flow_is_unchanged_by_the_whatsapp_integration(): void
    {
        $this->useCloudApiWhatsApp();
        Http::fake();

        // El endpoint web existente no pasa por el webhook: sin envíos.
        $response = $this->postJson('/api/chat', [
            'userIdentifier' => 'web-flow-user',
            'message' => 'hola',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(0, Conversation::where('channel', 'whatsapp')->count());
        $this->assertWhatsAppSentCount(0);
    }
}
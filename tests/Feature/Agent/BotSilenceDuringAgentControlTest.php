<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Message;

/**
 * Handoff silencioso: mientras un agente humano tiene el control (assigned /
 * attending) el chatbot NO responde de forma automática; solo se registra el
 * mensaje del cliente para que el agente lo atienda desde el dashboard.
 */
class BotSilenceDuringAgentControlTest extends AgentTestCase
{
    public function test_bot_is_silent_when_the_conversation_is_assigned(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-asignada',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-asignada',
            'message' => 'Quiero cita para limpieza dental',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '')
            ->assertJsonPath('data.handled_by', 'agent')
            ->assertJsonPath('data.conversation_id', $conversation->id);

        $conversation->refresh();

        $this->assertSame(ConversationState::ASSIGNED, $conversation->state);
        $this->assertSame(0, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_USER)->count());
        $this->assertSame(1, $conversation->unreadMessagesCount());
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_bot_is_silent_when_the_conversation_is_being_attended(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-atendida',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-atendida',
            'message' => 'hola',
        ])
            ->assertOk()
            ->assertJsonPath('message', '')
            ->assertJsonPath('data.handled_by', 'agent');

        $this->assertSame(ConversationState::ATTENDING, $conversation->fresh()->state);
        $this->assertSame(0, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
    }

    public function test_agent_message_is_not_overwritten_by_a_bot_reply(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-conversacion',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('agent.conversations.reply', $conversation), [
            'message' => 'Hola, soy Laura de Cabanillas Dental.',
        ]);

        $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-conversacion',
            'message' => 'Perfecto, gracias',
        ])->assertJsonPath('message', '');

        $messages = $conversation->fresh()->messages()->orderBy('id')->get();

        $this->assertSame(['agent', 'user'], $messages->pluck('sender')->all());
    }

    public function test_bot_keeps_replying_when_it_controls_the_conversation(): void
    {
        $conversation = $this->conversation([
            'user_identifier' => 'web-bot',
            'state' => ConversationState::IDLE,
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-bot',
            'message' => 'hola',
        ])->assertOk()->assertJsonPath('data.handled_by', 'bot');

        $this->assertNotSame('', $response->json('message'));
        $this->assertSame(1, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
    }

    public function test_bot_handoff_behaviour_is_unchanged(): void
    {
        $conversation = $this->conversation([
            'user_identifier' => 'web-handoff',
            'state' => ConversationState::IDLE,
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-handoff',
            'message' => 'quiero hablar con una persona',
        ])->assertOk()->assertJsonPath('data.intent', 'human_handoff');

        // El bot avisa que ya está en manos del equipo (comportamiento original).
        $this->assertNotSame('', $response->json('message'));

        $conversation->refresh();
        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);

        // La conversación queda en la cola de espera para los agentes.
        $this->actingAs($this->agent())
            ->get('/agent/conversations?filter=waiting')
            ->assertOk()
            ->assertSee('web-handoff');
    }

    public function test_bot_replies_again_after_the_conversation_is_returned_to_the_bot(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-devuelta',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('agent.conversations.release', $conversation));

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-devuelta',
            'message' => 'hola',
        ])->assertOk()->assertJsonPath('data.handled_by', 'bot');

        $this->assertNotSame('', $response->json('message'));
        $this->assertTrue($conversation->fresh()->state->isBotControlled());
    }

    public function test_customer_messages_during_attending_are_visible_to_agent_without_bot_interference(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-attending-customer',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        // 1. Mensaje del cliente mientras el agente atiende
        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-attending-customer',
            'message' => '¿Siguen ahí? Necesito una consulta dental.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.handled_by', 'agent')
            ->assertJsonPath('message', '');

        $conversation->refresh();

        // 2. Comprobar que no hay respuesta del bot y el mensaje del usuario quedó registrado
        $this->assertSame(0, $conversation->messages()->where('sender', Message::SENDER_BOT)->count());
        $userMsg = $conversation->messages()->where('sender', Message::SENDER_USER)->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('¿Siguen ahí? Necesito una consulta dental.', $userMsg->message);
        $this->assertNull($userMsg->read_at);
        $this->assertSame(1, $conversation->unreadMessagesCount());

        // 3. El mensaje es visible en el hilo para el agente vía HTML/endpoint de hilo
        $this->actingAs($agent)
            ->get(route('agent.conversations.thread', $conversation))
            ->assertOk()
            ->assertSee('¿Siguen ahí? Necesito una consulta dental.');

        // 4. El agente responde al cliente
        $this->actingAs($agent)
            ->postJson(route('agent.conversations.reply', $conversation), [
                'message' => 'Hola, sí, aquí estoy. Te ayudo con tu consulta.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // 5. El mensaje del usuario quedó marcado como leído tras la respuesta del agente
        $this->assertSame(0, $conversation->fresh()->unreadMessagesCount());

        // 6. El agente devuelve la conversación al bot
        $this->actingAs($agent)
            ->post(route('agent.conversations.release', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $this->assertSame(ConversationState::IDLE, $conversation->fresh()->state);

        // 7. El cliente escribe nuevamente y el bot reanuda su respuesta automáticamente
        $botResponse = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-attending-customer',
            'message' => 'servicios',
        ]);

        $botResponse->assertOk()
            ->assertJsonPath('data.handled_by', 'bot');
        $this->assertNotSame('', $botResponse->json('message'));
        $this->assertSame(1, $conversation->fresh()->messages()->where('sender', Message::SENDER_BOT)->count());
    }

    public function test_customer_messages_during_waiting_agent_are_persisted_without_bot_processing(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-waiting-customer',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $response = $this->postJson('/api/chat', [
            'conversation_id' => $conversation->id,
            'userIdentifier' => 'web-waiting-customer',
            'message' => '¿Hay alguien disponible ahora?',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.handled_by', 'agent')
            ->assertJsonPath('message', '');

        $conversation->refresh();
        $this->assertSame(ConversationState::WAITING_AGENT, $conversation->state);
        $this->assertSame(1, $conversation->unreadMessagesCount());

        // El agente toma la conversación desde la cola
        $this->actingAs($agent)
            ->post(route('agent.conversations.take', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        // Tomar la conversación marca los mensajes pendientes como leídos
        $this->assertSame(0, $conversation->fresh()->unreadMessagesCount());
        $this->assertSame(ConversationState::ASSIGNED, $conversation->fresh()->state);
    }
}

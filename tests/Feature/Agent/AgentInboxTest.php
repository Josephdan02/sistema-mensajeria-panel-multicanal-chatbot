<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;

class AgentInboxTest extends AgentTestCase
{
    public function test_inbox_shows_conversations_waiting_for_an_agent(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-esperando',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);

        $this->conversation([
            'user_identifier' => 'web-bot',
            'state' => ConversationState::IDLE,
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations?filter=waiting')
            ->assertOk()
            ->assertSee('web-esperando')
            ->assertDontSee('web-bot')
            ->assertSee('Esperando agente');
    }

    public function test_inbox_opens_with_the_all_filter_when_none_is_requested(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-en-atencion',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->conversation([
            'user_identifier' => 'web-bot-ocioso',
            'state' => ConversationState::IDLE,
        ]);

        $response = $this->actingAs($agent)->get('/agent/conversations');

        $response->assertOk()
            // «Todas» es el filtro activo por defecto...
            ->assertSee('is-active', false)
            // ...y la lista incluye conversaciones de distintos estados.
            ->assertSee('web-en-atencion')
            ->assertSee('web-bot-ocioso');

        $html = $response->getContent();

        // Solo hay un chip activo y es el de «Todas» (Blade deja un espacio
        // variable entre clases, por eso la expresión tolera espacios).
        $this->assertSame(1, preg_match_all('/class="chip-link\s+is-active\s*"/', $html));
        $this->assertMatchesRegularExpression(
            '/class="chip-link\s+is-active\s*"[^>]*>Todas</',
            $html
        );
    }

    public function test_inbox_filters_by_attending_state(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-atencion',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->conversation([
            'user_identifier' => 'web-esperando',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->conversation([
            'user_identifier' => 'web-derivada',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);

        $this->conversation([
            'user_identifier' => 'web-bot-ocioso',
            'state' => ConversationState::IDLE,
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations?filter=attending')
            ->assertOk()
            ->assertSee('web-atencion')
            ->assertDontSee('web-esperando');

        // La cola de espera agrupa ambas variantes pendientes de un humano.
        $this->actingAs($agent)
            ->get('/agent/conversations?filter=waiting')
            ->assertOk()
            ->assertSee('web-esperando')
            ->assertSee('web-derivada')
            ->assertDontSee('web-atencion')
            ->assertDontSee('web-bot-ocioso');

        // El filtro idle muestra solo el bot sin conversación en curso.
        $this->actingAs($agent)
            ->get('/agent/conversations?filter=idle')
            ->assertOk()
            ->assertSee('web-bot-ocioso')
            ->assertDontSee('web-atencion');
    }

    public function test_inbox_shows_unread_counter_and_assigned_agent(): void
    {
        $agent = $this->agent(['name' => 'Laura Agente']);

        $conversation = $this->conversation([
            'user_identifier' => 'web-sin-leer',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);
        $this->customerMessage($conversation, 'Mensaje uno');
        $this->customerMessage($conversation, 'Mensaje dos');

        $this->actingAs($agent)
            ->get('/agent/conversations?filter=assigned')
            ->assertOk()
            ->assertSee('web-sin-leer')
            ->assertSee('Laura Agente')
            ->assertSee('Mensaje dos');
    }

    public function test_inbox_search_filters_by_identifier(): void
    {
        $agent = $this->agent();

        $this->conversation(['user_identifier' => 'web-12345678', 'state' => ConversationState::IDLE]);
        $this->conversation(['user_identifier' => 'web-99999999', 'state' => ConversationState::IDLE]);

        $this->actingAs($agent)
            ->get('/agent/conversations?filter=all&search=12345678')
            ->assertOk()
            ->assertSee('web-12345678')
            ->assertDontSee('web-99999999');
    }

    public function test_sidebar_partial_is_served_as_html_for_polling(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-esperando',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations/sidebar?filter=waiting')
            ->assertOk()
            ->assertSee('conv-item', false)
            ->assertSee('web-esperando');
    }

    public function test_conversation_view_shows_state_agent_and_messages(): void
    {
        $agent = $this->agent(['name' => 'Carlos Agente']);

        $conversation = $this->conversation([
            'user_identifier' => 'web-detalle',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->customerMessage($conversation, 'Hola, necesito una cita');
        \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'sender' => \App\Models\Message::SENDER_BOT,
            'message' => '¡Hola! ¿Qué servicio necesitas?',
        ]);
        \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'sender' => \App\Models\Message::SENDER_AGENT,
            'message' => 'Te ayudo con la cita.',
            'agent_id' => $agent->id,
            'read_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('web-detalle')
            ->assertSee('Hola, necesito una cita')
            ->assertSee('¡Hola! ¿Qué servicio necesitas?')
            ->assertSee('Te ayudo con la cita.')
            ->assertSee('Carlos Agente')
            ->assertSee('En atención');
    }

    public function test_opening_a_conversation_marks_customer_messages_as_read(): void
    {
        $agent = $this->agent();

        $conversation = $this->conversation([
            'user_identifier' => 'web-lectura',
            'state' => ConversationState::WAITING_AGENT,
        ]);
        $this->customerMessage($conversation, 'Mensaje pendiente');

        $this->assertSame(1, $conversation->unreadMessagesCount());

        $this->actingAs($agent)->get('/agent/conversations/'.$conversation->id)->assertOk();

        $this->assertSame(0, $conversation->fresh()->unreadMessagesCount());
    }

    public function test_thread_partial_is_served_as_html_for_polling(): void
    {
        $agent = $this->agent();

        $conversation = $this->conversation(['user_identifier' => 'web-hilo']);
        $this->customerMessage($conversation, 'Mensaje del cliente');

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id.'/thread')
            ->assertOk()
            ->assertSee('msg--user', false)
            ->assertSee('Mensaje del cliente');
    }
}

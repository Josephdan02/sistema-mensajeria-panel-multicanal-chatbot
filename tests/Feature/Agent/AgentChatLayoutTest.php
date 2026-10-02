<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;

/**
 * Estructura del área de chat (UX tipo mensajería).
 *
 * Verifica que la vista de conversación mantenga los contenedores de los que
 * dependen el CSS (scroll propio del historial, composer siempre visible) y el
 * JS de polling/scroll sin tocar la lógica de negocio.
 */
class AgentChatLayoutTest extends AgentTestCase
{
    public function test_conversation_view_uses_the_full_height_chat_shell(): void
    {
        $agent = $this->agent();

        $conversation = $this->conversation([
            'user_identifier' => 'web-layout',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('agent-app--shell', false)   // body sin scroll de página
            ->assertSee('workspace--thread', false)
            ->assertSee('chat-thread-wrap', false)   // contenedor del scroller y del aviso
            ->assertSee('id="chat-thread"', false)   // scroller del historial
            ->assertSee('data-chat-jump', false)     // aviso de nuevos mensajes
            ->assertSee('data-reply-form', false)    // composer
            ->assertSee('data-reply-send', false);   // botón Enviar junto al campo
    }

    public function test_conversation_actions_live_in_the_contact_panel(): void
    {
        $agent = $this->agent();

        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('contact-actions', false)
            ->assertSee('/agent/conversations/'.$conversation->id.'/assign', false)
            ->assertSee('/agent/conversations/'.$conversation->id.'/state', false)
            ->assertDontSee('class="actions', false);
    }

    public function test_message_thread_includes_a_day_separator(): void
    {
        $agent = $this->agent();

        $conversation = $this->conversation(['user_identifier' => 'web-hoy']);
        $this->customerMessage($conversation, 'Mensaje de hoy');

        $this->actingAs($agent)
            ->get('/agent/conversations/'.$conversation->id.'/thread')
            ->assertOk()
            ->assertSee('msg-day', false)
            ->assertSee('Hoy')
            ->assertSee('Mensaje de hoy');
    }
}

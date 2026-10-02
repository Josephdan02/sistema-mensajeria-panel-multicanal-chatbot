<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;

class AgentDashboardTest extends AgentTestCase
{
    public function test_dashboard_lists_waiting_and_assigned_conversations(): void
    {
        $agent = $this->agent();

        $waiting = $this->conversation([
            'user_identifier' => 'web-esperando',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);
        $this->customerMessage($waiting, 'Necesito hablar con una persona');

        $mine = $this->conversation([
            'user_identifier' => 'web-mia',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->conversation([
            'user_identifier' => 'web-cerrada',
            'state' => ConversationState::CLOSED,
            'closed_at' => now(),
            'closed_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get('/agent/dashboard');

        $response->assertOk()
            ->assertSee('web-esperando')
            ->assertSee('web-mia')
            ->assertSee('Esperando agente')
            ->assertSee('Mis conversaciones');
    }

    public function test_dashboard_counters_are_exposed_as_json(): void
    {
        $agent = $this->agent();

        $waiting = $this->conversation([
            'user_identifier' => 'web-esperando',
            'state' => ConversationState::WAITING_AGENT,
        ]);
        $this->customerMessage($waiting, 'Mensaje sin leer');

        $this->conversation([
            'user_identifier' => 'web-asignada',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->conversation([
            'user_identifier' => 'web-atendiendo',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->conversation([
            'user_identifier' => 'web-cerrada',
            'state' => ConversationState::CLOSED,
            'closed_at' => now(),
        ]);

        // Conversación atendida por el bot (idle) con mensaje del cliente.
        $bot = $this->conversation([
            'user_identifier' => 'web-bot',
            'state' => ConversationState::IDLE,
        ]);
        $this->customerMessage($bot, 'Hola bot');

        $this->actingAs($agent)
            ->getJson('/agent/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.waiting', 1)
            ->assertJsonPath('data.assigned', 1)
            ->assertJsonPath('data.attending', 1)
            ->assertJsonPath('data.closed', 1)
            ->assertJsonPath('data.bot', 1)
            ->assertJsonPath('data.mine', 2)
            ->assertJsonPath('data.unread', 1);
    }

    public function test_unread_counter_only_considers_conversations_waiting_for_a_human(): void
    {
        $agent = $this->agent();

        $attending = $this->conversation([
            'user_identifier' => 'web-atendiendo',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);
        $this->customerMessage($attending, 'Primer mensaje sin leer');
        $this->customerMessage($attending, 'Segundo mensaje sin leer');
        $this->customerMessage($attending, 'Mensaje ya leído', true);

        $this->actingAs($agent)
            ->getJson('/agent/dashboard/stats')
            ->assertJsonPath('data.unread', 2);
    }
}

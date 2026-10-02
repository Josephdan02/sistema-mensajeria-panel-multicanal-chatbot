<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\User;

class AgentNotificationsTest extends AgentTestCase
{
    public function test_notifications_summary_returns_unread_and_waiting_counts(): void
    {
        $agent = $this->agent();

        $waiting = $this->conversation([
            'user_identifier' => 'web-espera',
            'state' => ConversationState::WAITING_AGENT,
            'last_message_at' => now(),
        ]);
        $this->customerMessage($waiting, 'Hola, necesito ayuda');

        $mine = $this->conversation([
            'user_identifier' => 'web-mia',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
            'last_message_at' => now(),
        ]);
        $this->customerMessage($mine, 'Otra pregunta');

        $this->actingAs($agent)
            ->getJson(route('agent.notifications.summary'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.waiting', 1)
            ->assertJsonPath('data.mine', 1)
            ->assertJsonPath('data.unread', 2)
            ->assertJsonPath('data.unread_waiting', 1);
    }

    public function test_notifications_require_agent_login(): void
    {
        $this->getJson(route('agent.notifications.summary'))->assertUnauthorized();
    }

    public function test_settings_page_loads_and_topbar_has_bell(): void
    {
        $agent = $this->agent(['role' => User::ROLE_AGENT]);

        $this->actingAs($agent)
            ->get(route('agent.settings.index'))
            ->assertOk()
            ->assertSee('Ajustes del centro')
            ->assertSee('data-agent-notifications', false)
            ->assertSee('data-settings-polling', false);
    }

    public function test_nav_shows_users_link_only_for_supervisors(): void
    {
        $agent = $this->agent(['role' => User::ROLE_AGENT]);
        $supervisor = $this->agent(['role' => User::ROLE_SUPERVISOR, 'email' => 'sup-nav@cabanillas.test']);

        $this->actingAs($agent)->get(route('agent.dashboard'))
            ->assertOk()
            ->assertDontSee(route('agent.users.index'), false);

        $this->actingAs($supervisor)->get(route('agent.dashboard'))
            ->assertOk()
            ->assertSee(route('agent.users.index'), false);
    }
}

<?php

namespace Tests\Feature\Agent;

use App\Models\User;

class AgentAuthenticationTest extends AgentTestCase
{
    public function test_guest_is_redirected_to_agent_login(): void
    {
        $this->get('/agent/dashboard')->assertRedirect('/agent/login');
    }

    public function test_login_page_is_available_for_guests(): void
    {
        $this->get('/agent/login')
            ->assertOk()
            ->assertSee('Centro de Agentes');
    }

    public function test_agent_can_login_and_reach_the_dashboard(): void
    {
        $agent = $this->agent(['email' => 'agente@cabanillas.test']);

        $response = $this->post('/agent/login', [
            'email' => 'agente@cabanillas.test',
            'password' => 'agente1234',
        ]);

        $response->assertRedirect('/agent/dashboard');
        $this->assertAuthenticatedAs($agent);
        $this->assertNotNull($agent->fresh()->last_seen_at);

        $this->get('/agent/dashboard')->assertOk()->assertSee('Agente de Prueba');
    }

    public function test_user_without_agent_role_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'usuario@example.com',
            'password' => 'secreto1234',
            'role' => null,
        ]);

        $response = $this->from('/agent/login')->post('/agent/login', [
            'email' => 'usuario@example.com',
            'password' => 'secreto1234',
        ]);

        $response->assertRedirect('/agent/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull($user->fresh()->last_seen_at);
    }

    public function test_inactive_agent_cannot_login(): void
    {
        $this->agent([
            'email' => 'inactivo@cabanillas.test',
            'active' => false,
        ]);

        $this->from('/agent/login')->post('/agent/login', [
            'email' => 'inactivo@cabanillas.test',
            'password' => 'agente1234',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->agent(['email' => 'agente@cabanillas.test']);

        $this->from('/agent/login')->post('/agent/login', [
            'email' => 'agente@cabanillas.test',
            'password' => 'incorrecta',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_non_agent_session_is_rejected_from_agent_area(): void
    {
        $user = User::factory()->create(['role' => null]);

        $this->actingAs($user)->get('/agent/dashboard')->assertRedirect('/agent/login');
        $this->assertGuest();
    }

    public function test_non_agent_cannot_reach_agent_json_endpoints(): void
    {
        $user = User::factory()->create(['role' => null]);

        $this->actingAs($user)
            ->getJson('/agent/dashboard/stats')
            ->assertStatus(403);
    }

    public function test_supervisor_role_is_recognised(): void
    {
        $supervisor = $this->supervisor(['email' => 'supervisor@cabanillas.test']);

        $this->actingAs($supervisor)->get('/agent/dashboard')->assertOk();
        $this->assertTrue($supervisor->isSupervisor());
        $this->assertSame('Supervisor', $supervisor->roleLabel());
    }

    public function test_logout_ends_the_agent_session(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)->post('/agent/logout')->assertRedirect('/agent/login');
        $this->assertGuest();

        $this->get('/agent/dashboard')->assertRedirect('/agent/login');
    }
}

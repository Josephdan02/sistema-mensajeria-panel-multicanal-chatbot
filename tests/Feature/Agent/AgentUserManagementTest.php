<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AgentUserManagementTest extends AgentTestCase
{
    public function test_agent_cannot_access_user_admin(): void
    {
        $agent = $this->agent(['role' => User::ROLE_AGENT]);

        $this->actingAs($agent)->get(route('agent.users.index'))->assertRedirect(route('agent.dashboard'));
        $this->actingAs($agent)->get(route('agent.users.create'))->assertRedirect(route('agent.dashboard'));
        $this->actingAs($agent)->post(route('agent.users.store'), [])->assertRedirect(route('agent.dashboard'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('agent.users.index'))->assertRedirect(route('agent.login'));
    }

    public function test_supervisor_can_list_only_agent_users(): void
    {
        $supervisor = $this->agent(['role' => User::ROLE_SUPERVISOR]);
        User::factory()->create(['role' => null, 'active' => true]);

        $this->actingAs($supervisor)
            ->get(route('agent.users.index'))
            ->assertOk()
            ->assertSee($supervisor->name)
            ->assertDontSee('Sin rol de agente');
    }

    public function test_supervisor_can_create_agent(): void
    {
        $supervisor = $this->agent(['role' => User::ROLE_SUPERVISOR]);

        $response = $this->actingAs($supervisor)->post(route('agent.users.store'), [
            'name' => 'Nueva Agente',
            'email' => 'nueva@cabanillas.test',
            'password' => 'secreta123',
            'password_confirmation' => 'secreta123',
            'role' => User::ROLE_AGENT,
            'active' => '1',
        ]);

        $response->assertRedirect(route('agent.users.index'));

        $created = User::query()->where('email', 'nueva@cabanillas.test')->firstOrFail();
        $this->assertTrue($created->isAgent());
        $this->assertTrue(Hash::check('secreta123', $created->password));
    }

    public function test_supervisor_can_edit_toggle_and_reset_password(): void
    {
        $supervisor = $this->agent(['role' => User::ROLE_SUPERVISOR, 'email' => 'sup@cabanillas.test']);
        $managed = $this->agent(['email' => 'edit@cabanillas.test']);

        $this->actingAs($supervisor)->put(route('agent.users.update', $managed), [
            'name' => 'Editada',
            'email' => 'edit@cabanillas.test',
            'role' => User::ROLE_AGENT,
            'active' => '1',
        ])->assertRedirect(route('agent.users.index'));

        $this->assertSame('Editada', $managed->refresh()->name);

        // Desactivar y reactivar.
        $this->actingAs($supervisor)->patch(route('agent.users.toggle', $managed))->assertRedirect();
        $this->assertFalse($managed->refresh()->active);
        $this->actingAs($supervisor)->patch(route('agent.users.toggle', $managed))->assertRedirect();
        $this->assertTrue($managed->refresh()->active);

        // Restablecer contraseña genera una temporal distinta.
        $oldHash = $managed->password;
        $this->actingAs($supervisor)->post(route('agent.users.reset-password', $managed))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertNotSame($oldHash, $managed->refresh()->password);
    }

    public function test_supervisor_cannot_deactivate_self_or_last_supervisor(): void
    {
        $only = $this->agent(['role' => User::ROLE_SUPERVISOR, 'email' => 'unico@cabanillas.test']);

        $this->actingAs($only)->patch(route('agent.users.toggle', $only))
            ->assertRedirect()
            ->assertSessionHasErrors('active');
        $this->assertTrue($only->refresh()->active);

        $this->actingAs($only)->put(route('agent.users.update', $only), [
            'name' => $only->name,
            'email' => $only->email,
            'role' => User::ROLE_AGENT,
            'active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertTrue($only->refresh()->isSupervisor());
    }

    public function test_degraded_supervisor_loses_access(): void
    {
        $first = $this->agent(['role' => User::ROLE_SUPERVISOR, 'email' => 's1@cabanillas.test']);
        $second = $this->agent(['role' => User::ROLE_SUPERVISOR, 'email' => 's2@cabanillas.test']);

        // Con dos supervisores sí se puede degradar a uno.
        $this->actingAs($first)->put(route('agent.users.update', $second), [
            'name' => $second->name,
            'email' => $second->email,
            'role' => User::ROLE_AGENT,
            'active' => '1',
        ])->assertRedirect(route('agent.users.index'));

        $this->actingAs($second->refresh())->get(route('agent.users.index'))
            ->assertRedirect(route('agent.dashboard'));
    }
}

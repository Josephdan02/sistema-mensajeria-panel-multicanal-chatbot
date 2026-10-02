<?php

namespace Tests\Feature\Agent;

/**
 * PERFIL: el agente ve su nombre/email y puede cambiarlos junto con su
 * contraseña, sin romper el login actual.
 */
class AgentProfileTest extends AgentTestCase
{
    public function test_profile_shows_name_and_email(): void
    {
        $agent = $this->agent(['name' => 'Ana Agente', 'email' => 'ana@cabanillas.test']);

        $this->actingAs($agent)
            ->get('/agent/profile')
            ->assertOk()
            ->assertSee('Ana Agente')
            ->assertSee('ana@cabanillas.test');
    }

    public function test_profile_update_changes_name_and_email(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->put('/agent/profile', ['name' => 'Nuevo Nombre', 'email' => 'nuevo@cabanillas.test'])
            ->assertRedirect('/agent/profile');

        $this->assertSame('Nuevo Nombre', $agent->fresh()->name);
        $this->assertSame('nuevo@cabanillas.test', $agent->fresh()->email);
    }

    public function test_profile_update_rejects_a_duplicate_email(): void
    {
        $this->agent(['email' => 'ocupado@cabanillas.test']);
        $agent = $this->agent(['email' => 'libre@cabanillas.test']);

        $this->actingAs($agent)
            ->put('/agent/profile', ['name' => 'Agente', 'email' => 'ocupado@cabanillas.test'])
            ->assertSessionHasErrors('email');

        $this->assertSame('libre@cabanillas.test', $agent->fresh()->email);
    }

    public function test_profile_password_change_keeps_the_login_working(): void
    {
        $agent = $this->agent(['email' => 'clave@cabanillas.test']);

        $this->actingAs($agent)
            ->put('/agent/profile/password', [
                'current_password' => 'agente1234',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertRedirect('/agent/profile');

        $this->post('/agent/logout');

        $this->post('/agent/login', ['email' => 'clave@cabanillas.test', 'password' => 'nueva-clave-123'])
            ->assertRedirect('/agent/dashboard');
    }

    public function test_profile_password_change_requires_the_current_password(): void
    {
        $agent = $this->agent();

        $this->actingAs($agent)
            ->put('/agent/profile/password', [
                'current_password' => 'clave-incorrecta',
                'password' => 'nueva-clave-123',
                'password_confirmation' => 'nueva-clave-123',
            ])
            ->assertSessionHasErrors('current_password');
    }
}

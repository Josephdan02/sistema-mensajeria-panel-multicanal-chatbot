<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios de prueba del Sistema de Agente.
 *
 * Es idempotente (updateOrCreate) y no toca a los usuarios existentes.
 * Ejecutar con: php artisan db:seed --class=AgentUserSeeder
 */
class AgentUserSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->agents() as $agent) {
            User::query()->updateOrCreate(
                ['email' => $agent['email']],
                [
                    'name' => $agent['name'],
                    'password' => $agent['password'],
                    'role' => $agent['role'],
                    'active' => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array{name: string, email: string, password: string, role: string}>
     */
    public function agents(): array
    {
        return [
            [
                'name' => 'Agente de Prueba',
                'email' => 'agente@cabanillas.test',
                'password' => 'agente1234',
                'role' => User::ROLE_AGENT,
            ],
            [
                'name' => 'Supervisor de Prueba',
                'email' => 'supervisor@cabanillas.test',
                'password' => 'supervisor1234',
                'role' => User::ROLE_SUPERVISOR,
            ],
        ];
    }
}

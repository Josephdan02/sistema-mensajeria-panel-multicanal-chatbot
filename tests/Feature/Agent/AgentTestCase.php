<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base común para las pruebas del Sistema de Agente.
 */
abstract class AgentTestCase extends TestCase
{
    use RefreshDatabase;

    protected function agent(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Agente de Prueba',
            'email' => 'agente'.uniqid().'@cabanillas.test',
            'password' => 'agente1234',
            'role' => User::ROLE_AGENT,
            'active' => true,
        ], $attributes));
    }

    protected function supervisor(array $attributes = []): User
    {
        return $this->agent(array_merge([
            'name' => 'Supervisor de Prueba',
            'role' => User::ROLE_SUPERVISOR,
        ], $attributes));
    }

    protected function conversation(array $attributes = []): Conversation
    {
        return Conversation::create(array_merge([
            'user_identifier' => 'web-contacto',
            'state' => ConversationState::IDLE,
        ], $attributes));
    }

    protected function customerMessage(Conversation $conversation, string $text, bool $read = false): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => $text,
            'intent' => null,
            'read_at' => $read ? now() : null,
        ]);
    }
}

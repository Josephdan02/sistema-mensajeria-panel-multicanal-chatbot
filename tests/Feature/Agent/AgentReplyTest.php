<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Message;

class AgentReplyTest extends AgentTestCase
{
    public function test_agent_message_is_persisted_with_the_agent_sender(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-respuesta',
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now()->subMinutes(5),
        ]);
        $this->customerMessage($conversation, 'Hola, necesito ayuda');

        $this->actingAs($agent)
            ->post(route('agent.conversations.reply', $conversation), [
                'message' => 'Buenos días, ¿en qué te ayudo?',
            ])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::ATTENDING, $conversation->state);
        $this->assertNotNull($conversation->last_message_at);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => 'Buenos días, ¿en qué te ayudo?',
            'agent_id' => $agent->id,
        ]);

        $message = $conversation->messages()->where('sender', Message::SENDER_AGENT)->firstOrFail();

        $this->assertTrue($message->isFromAgent());
        $this->assertSame($agent->id, $message->agent?->id);
        $this->assertSame('Agente', $message->senderLabel());
        $this->assertSame(0, $conversation->unreadMessagesCount());
    }

    public function test_reply_returns_json_and_keeps_the_conversation_attending(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.reply', $conversation), ['message' => 'Te atiendo ahora.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.state', 'attending')
            ->assertJsonPath('data.state_label', 'En atención');
    }

    public function test_reply_assigns_the_conversation_when_it_had_no_agent(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-sin-agente',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->actingAs($agent)->post(route('agent.conversations.reply', $conversation), [
            'message' => 'Hola, soy tu agente.',
        ]);

        $conversation->refresh();

        $this->assertSame($agent->id, $conversation->assigned_agent_id);
        $this->assertNotNull($conversation->assigned_at);
        $this->assertSame(ConversationState::ATTENDING, $conversation->state);
    }

    public function test_reply_requires_a_message(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.reply', $conversation), ['message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertSame(0, $conversation->fresh()->messages()->where('sender', Message::SENDER_AGENT)->count());
    }

    public function test_guest_cannot_reply(): void
    {
        $conversation = $this->conversation(['state' => ConversationState::WAITING_AGENT]);

        $this->post(route('agent.conversations.reply', $conversation), ['message' => 'Hola'])
            ->assertRedirect('/agent/login');

        $this->assertSame(0, $conversation->fresh()->messages()->count());
    }
}

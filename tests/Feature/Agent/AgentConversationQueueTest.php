<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Message;

class AgentConversationQueueTest extends AgentTestCase
{
    public function test_agent_can_return_the_conversation_to_the_bot(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
            'context' => ['awaiting' => 'date', 'slots' => ['service' => 'Ortodoncia']],
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.release', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::IDLE, $conversation->state);
        $this->assertNull($conversation->assigned_agent_id);
        $this->assertNull($conversation->assigned_at);
        $this->assertSame([], $conversation->context ?? []);

        $system = $conversation->messages()->where('sender', Message::SENDER_SYSTEM)->latest('id')->first();
        $this->assertNotNull($system);
        $this->assertStringContainsString('devolvió la conversación al bot', $system->message);
    }

    public function test_agent_can_return_the_conversation_to_the_queue(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.queue', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::WAITING_AGENT, $conversation->state);
        $this->assertNull($conversation->assigned_agent_id);
        $this->assertTrue($conversation->state->isWaitingForAgent());
    }

    public function test_queued_conversation_appears_in_the_waiting_filter(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-en-cola',
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('agent.conversations.queue', $conversation));

        $this->actingAs($agent)
            ->get('/agent/conversations?filter=waiting')
            ->assertOk()
            ->assertSee('web-en-cola');
    }

    public function test_handoff_to_human_and_waiting_agent_share_the_waiting_bucket(): void
    {
        $agent = $this->agent();

        $this->conversation([
            'user_identifier' => 'web-handoff',
            'state' => ConversationState::HANDOFF_TO_HUMAN,
        ]);
        $this->conversation([
            'user_identifier' => 'web-en-cola',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->actingAs($agent)
            ->getJson('/agent/dashboard/stats')
            ->assertJsonPath('data.waiting', 2);
    }

    public function test_reply_marks_customer_messages_as_read(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ASSIGNED,
            'assigned_agent_id' => $agent->id,
        ]);
        $this->customerMessage($conversation, 'Mensaje pendiente');

        $this->actingAs($agent)->post(route('agent.conversations.reply', $conversation), [
            'message' => 'Ya te atiendo',
        ]);

        $this->assertSame(0, $conversation->fresh()->unreadMessagesCount());
    }
}

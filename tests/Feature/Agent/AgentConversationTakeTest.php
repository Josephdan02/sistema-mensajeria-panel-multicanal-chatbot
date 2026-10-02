<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;
use App\Models\Message;

class AgentConversationTakeTest extends AgentTestCase
{
    public function test_agent_can_take_a_waiting_conversation(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-tomar',
            'state' => ConversationState::WAITING_AGENT,
        ]);
        $this->customerMessage($conversation, 'Quiero hablar con alguien');

        $this->actingAs($agent)
            ->post(route('agent.conversations.take', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::ASSIGNED, $conversation->state);
        $this->assertSame($agent->id, $conversation->assigned_agent_id);
        $this->assertNotNull($conversation->assigned_at);
        $this->assertSame(0, $conversation->unreadMessagesCount());

        $system = $conversation->messages()->where('sender', Message::SENDER_SYSTEM)->latest('id')->first();
        $this->assertNotNull($system);
        $this->assertStringContainsString('tomó la conversación', $system->message);
    }

    public function test_take_returns_json_when_requested_by_fetch(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['state' => ConversationState::HANDOFF_TO_HUMAN]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.take', $conversation))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.state', 'assigned')
            ->assertJsonPath('data.state_label', 'Asignada')
            ->assertJsonPath('data.assigned_agent_id', $agent->id);
    }

    public function test_supervisor_can_assign_a_conversation_to_another_agent(): void
    {
        $supervisor = $this->supervisor();
        $target = $this->agent(['name' => 'Agente Destino']);
        $conversation = $this->conversation([
            'state' => ConversationState::WAITING_AGENT,
            'assigned_agent_id' => $supervisor->id,
        ]);

        $this->actingAs($supervisor)
            ->post(route('agent.conversations.assign', $conversation), ['agent_id' => $target->id])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame($target->id, $conversation->assigned_agent_id);
        $this->assertSame(ConversationState::ASSIGNED, $conversation->state);

        $system = $conversation->messages()->where('sender', Message::SENDER_SYSTEM)->latest('id')->first();
        $this->assertNotNull($system);
        $this->assertStringContainsString('Agente Destino', $system->message);
    }

    public function test_plain_agent_can_assign_an_unassigned_conversation(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'user_identifier' => 'web-sin-asignar',
            'state' => ConversationState::WAITING_AGENT,
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.assign', $conversation), ['agent_id' => $agent->id])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $this->assertSame($agent->id, $conversation->fresh()->assigned_agent_id);
    }

    public function test_plain_agent_cannot_take_over_a_conversation_assigned_to_someone_else(): void
    {
        $agent = $this->agent();
        $other = $this->agent(['name' => 'Otro Agente']);
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $other->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.assign', $conversation), ['agent_id' => $agent->id])
            ->assertStatus(403);

        $this->assertSame($other->id, $conversation->fresh()->assigned_agent_id);
    }

    public function test_agent_can_transfer_his_own_conversation_to_another_agent(): void
    {
        $agent = $this->agent();
        $other = $this->agent(['name' => 'Otro Agente']);
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.assign', $conversation), ['agent_id' => $other->id])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $this->assertSame($other->id, $conversation->fresh()->assigned_agent_id);
    }

    public function test_assignment_requires_an_active_agent(): void
    {
        $supervisor = $this->supervisor();
        $conversation = $this->conversation(['state' => ConversationState::WAITING_AGENT]);
        $inactive = $this->agent(['active' => false]);

        $this->actingAs($supervisor)
            ->postJson(route('agent.conversations.assign', $conversation), ['agent_id' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agent_id');
    }

    public function test_guest_cannot_take_a_conversation(): void
    {
        $conversation = $this->conversation(['state' => ConversationState::WAITING_AGENT]);

        $this->post(route('agent.conversations.take', $conversation))->assertRedirect('/agent/login');

        $this->assertSame(ConversationState::WAITING_AGENT, $conversation->fresh()->state);
    }
}

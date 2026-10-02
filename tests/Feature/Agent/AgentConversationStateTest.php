<?php

namespace Tests\Feature\Agent;

use App\Enums\ConversationState;

class AgentConversationStateTest extends AgentTestCase
{
    public function test_agent_can_change_the_conversation_state_to_attending(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['state' => ConversationState::WAITING_AGENT]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.state', $conversation), [
                'state' => ConversationState::ATTENDING->value,
                'note' => 'Paciente en línea',
            ])
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::ATTENDING, $conversation->state);
        $this->assertSame($agent->id, $conversation->assigned_agent_id);
    }

    public function test_conversation_state_must_be_one_of_the_selectable_states(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation(['state' => ConversationState::WAITING_AGENT]);

        $this->actingAs($agent)
            ->postJson(route('agent.conversations.state', $conversation), ['state' => 'inventado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('state');

        $this->assertSame(ConversationState::WAITING_AGENT, $conversation->fresh()->state);
    }

    public function test_agent_can_close_a_conversation(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
            'context' => ['awaiting' => 'date', 'slots' => ['service' => 'Ortodoncia']],
        ]);

        $this->actingAs($agent)
            ->post(route('agent.conversations.close', $conversation))
            ->assertRedirect(route('agent.conversations.show', $conversation));

        $conversation->refresh();

        $this->assertSame(ConversationState::CLOSED, $conversation->state);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame($agent->id, $conversation->closed_by);
        $this->assertSame([], $conversation->context ?? []);
        $this->assertSame($agent->id, $conversation->closedByUser->id);
    }

    public function test_closed_state_can_be_set_through_the_state_action(): void
    {
        $agent = $this->agent();
        $conversation = $this->conversation([
            'state' => ConversationState::ATTENDING,
            'assigned_agent_id' => $agent->id,
        ]);

        $this->actingAs($agent)->post(route('agent.conversations.state', $conversation), [
            'state' => ConversationState::CLOSED->value,
        ])->assertRedirect();

        $conversation->refresh();

        $this->assertSame(ConversationState::CLOSED, $conversation->state);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame($agent->id, $conversation->closed_by);
    }

    public function test_state_labels_are_translated_for_the_dashboard(): void
    {
        $this->assertSame('Esperando agente', ConversationState::HANDOFF_TO_HUMAN->label());
        $this->assertSame('Esperando agente', ConversationState::WAITING_AGENT->label());
        $this->assertSame('Asignada', ConversationState::ASSIGNED->label());
        $this->assertSame('En atención', ConversationState::ATTENDING->label());
        $this->assertSame('Cerrada', ConversationState::CLOSED->label());
    }

    public function test_legacy_states_keep_their_persisted_values(): void
    {
        $this->assertSame('idle', ConversationState::IDLE->value);
        $this->assertSame('in_progress', ConversationState::IN_PROGRESS->value);
        $this->assertSame('handoff_to_human', ConversationState::HANDOFF_TO_HUMAN->value);
        $this->assertSame('closed', ConversationState::CLOSED->value);
        $this->assertSame('waiting_agent', ConversationState::WAITING_AGENT->value);
        $this->assertSame('assigned', ConversationState::ASSIGNED->value);
        $this->assertSame('attending', ConversationState::ATTENDING->value);
    }
}

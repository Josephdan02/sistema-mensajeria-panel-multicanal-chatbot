<?php

namespace Tests\Unit;

use App\Enums\ConversationState;
use App\Enums\Intent;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
use App\Services\External\Agenda\AgendaServiceInterface;
use App\Services\Intent\RuleBasedIntentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ChatbotGlobalInterruptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function conversation(string $owner): Conversation
    {
        return Conversation::create([
            'user_identifier' => $owner,
            'state' => ConversationState::IDLE,
            'context' => [],
        ]);
    }

    private function chatbot(): ChatbotService
    {
        return app()->make(ChatbotService::class);
    }

    public function test_human_handoff_interrupts_an_active_flow(): void
    {
        $conversation = $this->conversation('interrupt-human-user');
        $conversation->setContextValue('awaiting', 'appointment_time');
        $conversation->setContextValue('slots.service', 'Limpieza dental');
        $conversation->save();

        $result = $this->chatbot()->handle($conversation, 'quiero hablar con una persona');

        $conversation->refresh();

        $this->assertSame('human_handoff', $result['intent']);
        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);
        $this->assertNull($conversation->getContextValue('awaiting'));
    }

    public function test_human_handoff_phrases_are_detected_without_classifying_unknown_messages_as_handoff(): void
    {
        $detector = new RuleBasedIntentDetector;
        $conversation = $this->conversation('intent-user');

        foreach ([
            'quiero hablar con algun miembro del personal',
            'quiero hablar con un asesor',
            'quiero hablar con una persona',
        ] as $message) {
            $this->assertSame('human_handoff', $detector->detect($message, $conversation)->value);
        }

        $this->assertSame('unknown', $detector->detect('necesito revisar este dato', $conversation)->value);
    }

    public function test_human_handoff_interrupts_reschedule_date_before_date_validation(): void
    {
        $conversation = $this->conversation('reschedule-human-user');
        $conversation->setContextValue('awaiting', 'reschedule_date');
        $conversation->setContextValue('slots.service', 'Limpieza dental');
        $conversation->save();

        $agenda = \Mockery::mock(AgendaServiceInterface::class);
        $agenda->shouldNotReceive('checkAvailability');
        $agenda->shouldNotReceive('rescheduleAppointment');
        $this->app->instance(AgendaServiceInterface::class, $agenda);

        $result = $this->chatbot()->handle(
            $conversation,
            'quiero hablar con algun miembro del personal',
        );

        $conversation->refresh();

        $this->assertSame('human_handoff', $result['intent']);
        $this->assertStringNotContainsString('fecha no es válida', strtolower($result['message']));
        $this->assertSame(ConversationState::HANDOFF_TO_HUMAN, $conversation->state);
        $this->assertNull($conversation->getContextValue('awaiting'));
        $this->assertSame(2, $conversation->messages()->count());
        $this->assertSame(1, $conversation->messages()->where('sender', 'bot')->count());
    }
}

<?php

namespace Tests\Unit;

use App\Enums\ConversationState;
use App\Enums\Intent;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
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
}

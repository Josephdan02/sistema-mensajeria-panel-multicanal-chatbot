<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotPageTest extends TestCase
{
    public function test_chatbot_page_loads_with_the_assistant_header(): void
    {
        $this->get('/chatbot')
            ->assertOk()
            ->assertSee('Asistente Virtual', false)
            ->assertSee('Clínica Dental', false);
    }

    public function test_chatbot_page_uses_the_real_chat_api_and_assets(): void
    {
        $response = $this->get('/chatbot');

        $response->assertOk();

        $html = $response->getContent();

        // La UI consume el endpoint real del mismo proyecto y sus assets estáticos.
        $this->assertStringContainsString('js/chatbot.js', $html);
        $this->assertStringContainsString('css/chatbot.css', $html);

        $script = file_get_contents(public_path('js/chatbot.js'));
        $this->assertStringContainsString("'/api/chat'", $script);
        $this->assertStringContainsString('user_identifier', $script);
        $this->assertStringContainsString('conversation_id', $script);
    }
}
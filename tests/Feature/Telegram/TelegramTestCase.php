<?php

namespace Tests\Feature\Telegram;

use App\Services\External\Telegram\TelegramApiService;
use App\Services\External\Telegram\TelegramServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class TelegramTestCase extends TestCase
{
    use RefreshDatabase;

    protected const WEBHOOK_SECRET = 'telegram-webhook-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.webhook_secret', self::WEBHOOK_SECRET);
        config()->set('services.telegram.api_url', 'https://api.telegram.org/bot');
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.timeout', 5);
        $this->app->instance(TelegramServiceInterface::class, new TelegramApiService);
    }

    protected function update(
        string|int $chatId,
        string $text,
        int $updateId = 1001,
    ): array {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    protected function postWebhook(array $payload, ?string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $headers = $secret === null ? [] : [
            'X-Telegram-Bot-Api-Secret-Token' => $secret,
        ];

        return $this->withHeaders($headers)->postJson('/api/telegram/webhook', $payload);
    }
}

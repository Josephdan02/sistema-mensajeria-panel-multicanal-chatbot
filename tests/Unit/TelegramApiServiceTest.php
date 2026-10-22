<?php

namespace Tests\Unit;

use App\Services\External\Telegram\TelegramApiService;
use App\Services\External\Telegram\TelegramSendStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramApiServiceTest extends TestCase
{
    private function service(): TelegramApiService
    {
        config()->set('services.telegram.api_url', 'https://api.telegram.org/bot');
        config()->set('services.telegram.bot_token', 'test-bot-token');
        config()->set('services.telegram.timeout', 5);

        return new TelegramApiService;
    }

    public function test_send_message_posts_telegram_payload(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->assertSame(TelegramSendStatus::SENT, $this->service()->sendMessage('12345', 'Hola desde Telegram')->status);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
                && $request['chat_id'] === '12345'
                && $request['text'] === 'Hola desde Telegram';
        });
    }

    public function test_send_message_returns_false_when_telegram_rejects(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request',
            ], 400),
        ]);

        $this->assertSame(TelegramSendStatus::FAILED, $this->service()->sendMessage('12345', 'Hola')->status);
    }

    public function test_send_message_returns_false_when_telegram_returns_ok_false_with_http_200(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Rejected',
            ], 200),
        ]);

        $this->assertSame(TelegramSendStatus::FAILED, $this->service()->sendMessage('12345', 'Hola')->status);
    }

    public function test_send_message_returns_false_on_network_failure(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection timed out'));

        $this->assertSame(TelegramSendStatus::UNKNOWN, $this->service()->sendMessage('12345', 'Hola')->status);
    }

    public function test_send_message_returns_false_when_configuration_is_missing(): void
    {
        config()->set('services.telegram.bot_token', '');
        Http::fake();

        $this->assertSame(TelegramSendStatus::FAILED, (new TelegramApiService)->sendMessage('12345', 'Hola')->status);
        Http::assertNothingSent();
    }
}

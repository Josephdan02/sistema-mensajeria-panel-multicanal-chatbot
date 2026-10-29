<?php

namespace Tests\Unit;

use App\Services\External\Telegram\TelegramApiService;
use App\Services\External\Telegram\TelegramFile;
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

    public function test_download_file_uses_the_telegram_file_endpoint_without_exposing_the_token_in_logs(): void
    {
        Http::fake([
            'api.telegram.org/file/*' => Http::response('contenido-binario', 200),
        ]);

        $file = new TelegramFile('file-1', 'unique-1', 'documents/informe.pdf', 18);

        $this->assertSame('contenido-binario', $this->service()->downloadFile($file));

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.telegram.org/file/bottest-bot-token/documents/informe.pdf');
    }

    public function test_download_file_returns_null_when_telegram_fails(): void
    {
        Http::fake([
            'api.telegram.org/file/*' => Http::response('no encontrado', 404),
        ]);

        $this->assertNull($this->service()->downloadFile(
            new TelegramFile('file-1', 'unique-1', 'documents/informe.pdf', 18),
        ));
    }

    public function test_download_file_returns_null_without_a_downloadable_path(): void
    {
        Http::fake();

        $this->assertNull($this->service()->downloadFile(new TelegramFile('file-1')));

        Http::assertNothingSent();
    }

    public function test_file_methods_include_the_caption_and_do_not_send_unreadable_files(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $path = tempnam(sys_get_temp_dir(), 'tg');

        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal.');
        }

        file_put_contents($path, 'contenido');

        try {
            $this->assertSame(
                TelegramSendStatus::SENT,
                $this->service()->sendPhoto('12345', $path, 'Pie de foto')->status,
            );

            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
                && str_contains($request->body(), 'name="caption"')
                && str_contains($request->body(), 'Pie de foto'));

            $this->assertSame(
                TelegramSendStatus::FAILED,
                $this->service()->sendDocument('12345', $path.'.missing')->status,
            );
        } finally {
            @unlink($path);
        }
    }
}

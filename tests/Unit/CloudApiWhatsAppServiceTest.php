<?php

namespace Tests\Unit;

use App\Services\External\WhatsApp\CloudApiWhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cliente WhatsApp Cloud API: payload correcto, normalización del número,
 * manejo de errores y configuración incompleta.
 */
class CloudApiWhatsAppServiceTest extends TestCase
{
    private function service(): CloudApiWhatsAppService
    {
        config()->set('services.whatsapp.api_url', 'https://graph.facebook.com');
        config()->set('services.whatsapp.api_version', 'v21.0');
        config()->set('services.whatsapp.access_token', 'token-123');
        config()->set('services.whatsapp.phone_number_id', 'PN-999');
        config()->set('services.whatsapp.timeout', 5);

        return new CloudApiWhatsAppService;
    }

    public function test_send_message_posts_the_expected_payload_and_headers(): void
    {
        Http::fake();

        $result = $this->service()->sendMessage('+54 9 11 2233-4455', 'Hola, ¿en qué te ayudo?');

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://graph.facebook.com/v21.0/PN-999/messages'
                && $request->hasHeader('Authorization', 'Bearer token-123')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '5491122334455'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hola, ¿en qué te ayudo?';
        });
    }

    public function test_send_message_returns_false_when_configuration_is_missing(): void
    {
        config()->set('services.whatsapp.access_token', '');
        config()->set('services.whatsapp.phone_number_id', '');
        Http::fake();

        $result = (new CloudApiWhatsAppService)->sendMessage('5491122334455', 'Hola');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_send_message_returns_false_when_graph_rejects(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401),
        ]);

        $result = $this->service()->sendMessage('5491122334455', 'Hola');

        $this->assertFalse($result);
    }

    public function test_send_message_returns_false_on_network_failure(): void
    {
        Http::fake(fn () => Http::failedConnection('Connection timed out'));

        $result = $this->service()->sendMessage('5491122334455', 'Hola');

        $this->assertFalse($result);
    }

    public function test_send_message_truncates_bodies_over_the_whatsapp_limit(): void
    {
        Http::fake();

        $long = str_repeat('a', 5000);
        $result = $this->service()->sendMessage('5491122334455', $long);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request): bool {
            return mb_strlen($request['text']['body']) === 4096;
        });
    }
}
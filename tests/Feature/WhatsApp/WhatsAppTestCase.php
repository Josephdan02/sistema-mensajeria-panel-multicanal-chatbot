<?php

namespace Tests\Feature\WhatsApp;

use App\Services\External\WhatsApp\CloudApiWhatsAppService;
use App\Services\External\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Agent\AgentTestCase;

/**
 * Base común para las pruebas de integración WhatsApp Cloud API.
 *
 * Configura credenciales de prueba, bindea CloudApiWhatsAppService real
 * (para capturar los envíos con Http::fake) y expone helpers para construir
 * payloads de Meta y firmar el webhook con X-Hub-Signature-256.
 */
abstract class WhatsAppTestCase extends AgentTestCase
{
    protected const APP_SECRET = 'test-app-secret';

    protected const VERIFY_TOKEN = 'test-verify-token';

    protected const PHONE_NUMBER_ID = '123456789';

    protected const ACCESS_TOKEN = 'test-access-token';

    /** Credenciales de prueba + servicio real capturado por Http::fake. */
    protected function useCloudApiWhatsApp(): void
    {
        config()->set('services.whatsapp.driver', 'api');
        config()->set('services.whatsapp.api_url', 'https://graph.facebook.com');
        config()->set('services.whatsapp.api_version', 'v21.0');
        config()->set('services.whatsapp.access_token', self::ACCESS_TOKEN);
        config()->set('services.whatsapp.phone_number_id', self::PHONE_NUMBER_ID);
        config()->set('services.whatsapp.verify_token', self::VERIFY_TOKEN);
        config()->set('services.whatsapp.app_secret', self::APP_SECRET);
        config()->set('services.whatsapp.timeout', 5);

        $this->app->instance(WhatsAppServiceInterface::class, new CloudApiWhatsAppService());
    }

    /** Payload estándar de Meta para un mensaje de texto entrante. */
    protected function inboundTextPayload(string $from, string $body, string $id = 'wamid.TEST1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                        'messages' => [[
                            'from' => $from,
                            'id' => $id,
                            'timestamp' => '1758000000',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    /** POST al webhook con firma X-Hub-Signature-256 calculada sobre el body. */
    protected function postWebhook(array $payload, string $secret = self::APP_SECRET): TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, $secret),
        ], $raw);
    }

    /** Envío saliente a graph.facebook.com con destinatario/cuerpo opcionales. */
    protected function assertWhatsAppSent(?string $to = null, ?string $body = null): void
    {
        Http::assertSent(function ($request) use ($to, $body): bool {
            if (! str_contains($request->url(), 'graph.facebook.com')) {
                return false;
            }

            if ($to !== null && $request['to'] !== $to) {
                return false;
            }

            if ($body !== null && ($request['text']['body'] ?? null) !== $body) {
                return false;
            }

            return true;
        });
    }

    /** Cantidad exacta de envíos salientes hacia graph.facebook.com. */
    protected function assertWhatsAppSentCount(int $count): void
    {
        $sent = Http::recorded(function ($request): bool {
            return str_contains($request->url(), 'graph.facebook.com');
        })->count();

        $this->assertSame($count, $sent, 'Cantidad inesperada de envíos a WhatsApp Cloud API.');
    }
}
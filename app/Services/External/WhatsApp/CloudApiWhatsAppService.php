<?php

namespace App\Services\External\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente real de WhatsApp Cloud API (Meta).
 *
 * Solo se preocupa de traducir sendMessage($to, $message) al contrato HTTP de
 * graph.facebook.com. NO conoce Conversations, estados ni ChatbotService: toda
 * la orquestación vive en WhatsAppInboundService / ConversationHandoffService.
 *
 * Errores de red o respuestas HTTP no exitosas se registran en el log y
 * devuelven false; nunca lanzan excepciones hacia el caller (el flujo del
 * panel y del webhook no debe romperse por un fallo de envío).
 */
class CloudApiWhatsAppService implements WhatsAppServiceInterface
{
    /** El cuerpo de texto de WhatsApp Cloud API admite hasta 4096 caracteres. */
    private const MAX_BODY_LENGTH = 4096;

    public function sendMessage(string $to, string $message): bool
    {
        $token = (string) config('services.whatsapp.access_token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

        if ($token === '' || $phoneNumberId === '') {
            Log::error('[WhatsApp] Configuración incompleta: falta access_token o phone_number_id.', [
                'to' => $to,
            ]);

            return false;
        }

        $url = sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('services.whatsapp.api_url', 'https://graph.facebook.com'), '/'),
            ltrim((string) config('services.whatsapp.api_version', 'v21.0'), '/'),
            $phoneNumberId,
        );

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.whatsapp.timeout', 10))
                ->acceptJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    // Meta espera el número en formato E.164 sin '+'.
                    'to' => ltrim(preg_replace('/[^0-9]/', '', $to), '+'),
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => mb_substr($message, 0, self::MAX_BODY_LENGTH),
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('[WhatsApp] Cloud API rechazó el mensaje.', [
                    'to' => $to,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('[WhatsApp] Error de red enviando mensaje.', [
                'to' => $to,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
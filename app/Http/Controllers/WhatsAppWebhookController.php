<?php

namespace App\Http\Controllers;

use App\Services\External\WhatsApp\WhatsAppInboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp Cloud API (Meta).
 *
 * GET  /api/whatsapp/webhook -> verificación del verify_token al registrar
 *      la URL en el panel de Meta.
 * POST /api/whatsapp/webhook -> mensajes entrantes; valida la firma
 *      X-Hub-Signature-256 (App Secret) y delega en WhatsAppInboundService.
 *
 * El controller es deliberadamente delgado (mismo patrón que ChatController):
 * validación de seguridad + delegación. Toda la lógica de conversación vive
 * en WhatsAppInboundService y ChatbotService.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppInboundService $inbound)
    {
    }

    /**
     * Verificación que hace Meta al registrar el webhook: devuelve el
     * hub.challenge en texto plano con 200 si el verify_token coincide.
     *
     * Nota: PHP convierte los puntos de los parámetros de query en guiones
     * bajos (hub.mode -> hub_mode), por eso se leen ambas variantes.
     */
    public function verify(Request $request): Response
    {
        $mode = $this->queryParam($request, 'hub.mode');
        $token = $this->queryParam($request, 'hub.verify_token');
        $challenge = $this->queryParam($request, 'hub.challenge');

        $expected = (string) config('services.whatsapp.verify_token');

        if ($mode !== 'subscribe' || $expected === '' || $challenge === '' || ! hash_equals($expected, $token)) {
            return response('Verificación inválida.', 403);
        }

        return response($challenge, 200);
    }

    /** Mensajes entrantes. Siempre responde 200 tras validar la firma. */
    public function receive(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('[WhatsApp] Webhook rechazado por firma inválida.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['success' => false, 'message' => 'Firma inválida.'], 403);
        }

        $payload = $request->json()->all();

        try {
            $this->inbound->handle(is_array($payload) ? $payload : []);
        } catch (\Throwable $exception) {
            // Meta espera 200 rápido: un error interno no debe provocar
            // reintentos en bucle. Se registra para diagnóstico.
            Log::error('[WhatsApp] Error procesando webhook.', [
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Procesado.']);
    }

    /**
     * X-Hub-Signature-256 = "sha256=" + HMAC-Sha256(cuerpo_raw, App Secret).
     * Fail-closed: sin app_secret configurado o sin header, se rechaza.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /** Lee un parámetro de query tolerando la conversión '.' -> '_' de PHP. */
    private function queryParam(Request $request, string $name): string
    {
        $query = $request->query->all();

        $value = $query[$name] ?? $query[str_replace('.', '_', $name)] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
<?php

namespace App\Http\Controllers;

use App\Services\External\Telegram\TelegramInboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramInboundService $inbound) {}

    public function receive(Request $request): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            Log::warning('[Telegram] Webhook rechazado por secret token inválido.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['success' => false, 'message' => 'Webhook inválido.'], 403);
        }

        $processedOk = true;

        try {
            $payload = $request->json()->all();
            $this->inbound->handle(is_array($payload) ? $payload : []);
        } catch (Throwable $exception) {
            $processedOk = false;
            Log::error('[Telegram] Error procesando webhook.', [
                'exception' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }

        return response()->json([
            'success' => $processedOk,
            'message' => $processedOk ? 'Procesado.' : 'Error interno.',
        ], $processedOk ? 200 : 500);
    }

    private function hasValidSecret(Request $request): bool
    {
        $expected = (string) config('services.telegram.webhook_secret', '');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}

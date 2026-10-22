<?php

namespace App\Services\External\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramApiService implements TelegramServiceInterface
{
    public function sendMessage(string $chatId, string $message): TelegramSendResult
    {
        $token = trim((string) config('services.telegram.bot_token', ''));
        $apiUrl = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org/bot'), '/');

        if ($token === '' || $apiUrl === '') {
            Log::error('[Telegram] Configuración incompleta: falta bot_token o api_url.', [
                'chat_id' => $chatId,
            ]);

            return TelegramSendResult::failed();
        }

        try {
            $response = Http::timeout((int) config('services.telegram.timeout', 10))
                ->acceptJson()
                ->post($apiUrl.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::error('[Telegram] Bot API rechazó el mensaje.', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                ]);

                return TelegramSendResult::failed();
            }

            return TelegramSendResult::sent();
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error de red enviando mensaje.', [
                'chat_id' => $chatId,
                'exception' => $exception->getMessage(),
            ]);

            return TelegramSendResult::unknown();
        }
    }
}

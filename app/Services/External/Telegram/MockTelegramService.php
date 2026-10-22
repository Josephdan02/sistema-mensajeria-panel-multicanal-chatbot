<?php

namespace App\Services\External\Telegram;

use Illuminate\Support\Facades\Log;

class MockTelegramService implements TelegramServiceInterface
{
    public function sendMessage(string $chatId, string $message): TelegramSendResult
    {
        Log::info('[MockTelegramService] Mensaje simulado enviado', [
            'chat_id' => $chatId,
            'message' => $message,
        ]);

        return TelegramSendResult::sent();
    }
}

<?php

namespace App\Services\External\Telegram;

interface TelegramServiceInterface
{
    public function sendMessage(string $chatId, string $message): TelegramSendResult;
}

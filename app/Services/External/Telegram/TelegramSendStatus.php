<?php

namespace App\Services\External\Telegram;

enum TelegramSendStatus: string
{
    case SENT = 'sent';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';
}

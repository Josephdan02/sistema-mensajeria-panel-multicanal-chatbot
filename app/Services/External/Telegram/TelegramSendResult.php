<?php

namespace App\Services\External\Telegram;

final readonly class TelegramSendResult
{
    private function __construct(
        public TelegramSendStatus $status,
    ) {}

    public static function sent(): self
    {
        return new self(TelegramSendStatus::SENT);
    }

    public static function failed(): self
    {
        return new self(TelegramSendStatus::FAILED);
    }

    public static function unknown(): self
    {
        return new self(TelegramSendStatus::UNKNOWN);
    }

    public function isSent(): bool
    {
        return $this->status === TelegramSendStatus::SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === TelegramSendStatus::FAILED;
    }

    public function isUnknown(): bool
    {
        return $this->status === TelegramSendStatus::UNKNOWN;
    }
}

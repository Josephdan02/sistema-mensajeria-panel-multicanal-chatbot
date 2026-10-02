<?php

namespace App\Enums;

/**
 * Origen de un mensaje persistido en `messages.sender`.
 *
 * Los valores históricos del chatbot ('user' y 'bot') se conservan tal cual
 * para no romper datos existentes ni el contrato de `POST /api/chat`.
 * El sistema de agentes agrega 'agent' y 'system'.
 */
enum MessageSender: string
{
    case CUSTOMER = 'user';

    case BOT = 'bot';

    case AGENT = 'agent';

    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Cliente',
            self::BOT => 'Bot',
            self::AGENT => 'Agente',
            self::SYSTEM => 'Sistema',
        };
    }

    public function isCustomer(): bool
    {
        return $this === self::CUSTOMER;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $sender): string => $sender->value, self::cases());
    }
}

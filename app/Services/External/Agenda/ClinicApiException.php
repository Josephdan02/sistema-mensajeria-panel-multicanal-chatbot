<?php

namespace App\Services\External\Agenda;

use RuntimeException;

class ClinicApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $operation = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configuration(string $message): self
    {
        return new self($message, null, 'configuration');
    }

    public static function unsupported(string $operation, string $message): self
    {
        return new self($message, null, $operation);
    }
}

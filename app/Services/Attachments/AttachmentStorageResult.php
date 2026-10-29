<?php

namespace App\Services\Attachments;

/**
 * Resultado de guardar un archivo en el almacenamiento privado.
 */
final class AttachmentStorageResult
{
    private function __construct(
        public readonly bool $stored,
        public readonly ?string $path = null,
        public readonly ?string $error = null,
    ) {}

    public static function stored(string $path): self
    {
        return new self(true, $path);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}

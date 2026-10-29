<?php

namespace App\Services\External\Telegram;

/**
 * Metadatos de un archivo alojado en Telegram (respuesta de `getFile`).
 */
final class TelegramFile
{
    public function __construct(
        public readonly string $fileId,
        public readonly ?string $fileUniqueId = null,
        public readonly ?string $filePath = null,
        public readonly ?int $fileSize = null,
    ) {}

    /** El archivo tiene ruta de descarga y se puede recuperar. */
    public function isDownloadable(): bool
    {
        return $this->filePath !== null && $this->filePath !== '';
    }
}

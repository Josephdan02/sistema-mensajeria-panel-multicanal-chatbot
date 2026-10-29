<?php

namespace App\Services\External\Telegram;

/**
 * Descriptor de un adjunto recibido en un mensaje de Telegram.
 */
final class TelegramAttachmentDescriptor
{
    public function __construct(
        public readonly string $type,
        public readonly string $fileId,
        public readonly ?string $fileUniqueId = null,
        public readonly ?string $fileName = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $fileSize = null,
    ) {}
}

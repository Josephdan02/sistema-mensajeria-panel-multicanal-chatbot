<?php

namespace App\Services\External\Telegram;

/**
 * Extrae el descriptor del adjunto presente en un mensaje de Telegram.
 *
 * Telegram envía el mismo archivo con estructuras distintas según el tipo:
 *  - `photo`: array de tamaños; se usa el mayor (`file_size` más grande).
 *  - `document` / `audio` / `video` / `voice` / `animation`: objeto con
 *    `file_id`, `file_name`, `mime_type` y `file_size`.
 *  - `sticker`: no se almacena (no está en la lista de tipos permitidos).
 */
class TelegramAttachmentParser
{
    /**
     * @param  array<string, mixed>  $message
     */
    public function parse(array $message): ?TelegramAttachmentDescriptor
    {
        foreach (['document', 'audio', 'voice', 'video', 'animation', 'photo'] as $type) {
            $payload = $message[$type] ?? null;

            if (! is_array($payload) || $payload === []) {
                continue;
            }

            $descriptor = $type === 'photo'
                ? $this->parsePhoto($payload)
                : $this->parseFile($type, $payload);

            if ($descriptor !== null) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sizes
     */
    private function parsePhoto(array $sizes): ?TelegramAttachmentDescriptor
    {
        $largest = null;

        foreach ($sizes as $size) {
            if (! is_array($size) || ! isset($size['file_id']) || ! is_string($size['file_id'])) {
                continue;
            }

            if ($largest === null || (int) ($size['file_size'] ?? 0) > (int) ($largest['file_size'] ?? 0)) {
                $largest = $size;
            }
        }

        if ($largest === null) {
            return null;
        }

        return new TelegramAttachmentDescriptor(
            type: 'photo',
            fileId: $largest['file_id'],
            fileUniqueId: is_string($largest['file_unique_id'] ?? null) ? $largest['file_unique_id'] : null,
            fileName: null,
            mimeType: 'image/jpeg',
            fileSize: is_int($largest['file_size'] ?? null) ? $largest['file_size'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseFile(string $type, array $payload): ?TelegramAttachmentDescriptor
    {
        $fileId = $payload['file_id'] ?? null;

        if (! is_string($fileId) || $fileId === '') {
            return null;
        }

        $fileName = is_string($payload['file_name'] ?? null) ? $payload['file_name'] : null;
        $mimeType = is_string($payload['mime_type'] ?? null) ? $payload['mime_type'] : null;
        $fileSize = is_int($payload['file_size'] ?? null) ? $payload['file_size'] : null;

        if ($fileName === null && $mimeType === null && $type !== 'voice' && $type !== 'animation') {
            return null;
        }

        return new TelegramAttachmentDescriptor(
            type: $type,
            fileId: $fileId,
            fileUniqueId: is_string($payload['file_unique_id'] ?? null) ? $payload['file_unique_id'] : null,
            fileName: $fileName,
            mimeType: $mimeType,
            fileSize: $fileSize,
        );
    }
}

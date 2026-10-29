<?php

namespace App\Services\Attachments;

/**
 * Archivo validado que el agente quiere enviar al cliente.
 *
 * `localPath` es la ruta física temporal del upload (la que necesitan los
 * métodos de Telegram); `contents` se usa para guardarlo en el almacenamiento
 * privado. Nunca se mezcla la ruta remota del objeto con la ruta local.
 */
final class OutgoingAttachment
{
    public function __construct(
        public readonly string $kind,
        public readonly string $originalName,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $localPath,
        public readonly string $contents,
    ) {}
}

<?php

namespace App\Enums;

/**
 * Estado de un adjunto recibido por un canal externo (Telegram).
 *
 * - `pending`    el adjunto se ha detectado pero todavía no se ha recuperado.
 * - `available`  el archivo está descargado y guardado en el almacenamiento
 *                privado; se puede previsualizar y descargar.
 * - `unavailable` el archivo ya no se puede recuperar (Telegram lo cerró, no
 *                existe la ruta de descarga o expiró).
 * - `too_large`  el archivo supera el límite permitido por categoría o el del
 *                propio Bot API de Telegram.
 * - `failed`     la descarga o el guardado falló (red, validación o storage).
 */
enum AttachmentStatus: string
{
    case PENDING = 'pending';

    case AVAILABLE = 'available';

    case UNAVAILABLE = 'unavailable';

    case TOO_LARGE = 'too_large';

    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Procesando',
            self::AVAILABLE => 'Disponible',
            self::UNAVAILABLE => 'No disponible',
            self::TOO_LARGE => 'Demasiado grande',
            self::FAILED => 'Error al recuperar',
        };
    }

    /** El archivo está guardado y se puede previsualizar o descargar. */
    public function isAvailable(): bool
    {
        return $this === self::AVAILABLE;
    }

    /** El adjunto tiene un error que se muestra al agente en el panel. */
    public function hasError(): bool
    {
        return in_array($this, [self::UNAVAILABLE, self::TOO_LARGE, self::FAILED], true);
    }
}

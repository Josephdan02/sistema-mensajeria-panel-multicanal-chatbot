<?php

namespace App\Services\Attachments;

use App\Services\External\Telegram\TelegramSendResult;
use App\Services\External\Telegram\TelegramServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validación, almacenamiento y envío de archivos que salen del panel.
 *
 * Reutiliza las mismas reglas de `config/attachments.php` que la ingesta de
 * archivos recibidos: categoría, MIME real, extensión y tamaño. El envío a
 * Telegram usa siempre la ruta física temporal del upload, nunca la clave del
 * objeto en el almacenamiento privado.
 */
class OutgoingAttachmentService
{
    public function __construct(
        private readonly AttachmentStorageInterface $storage,
        private readonly AttachmentContentInspector $inspector,
        private readonly TelegramServiceInterface $telegram,
    ) {}

    /**
     * Valida el upload y devuelve el archivo listo para almacenar/enviar.
     *
     * @throws ValidationException
     */
    public function prepare(UploadedFile $file): OutgoingAttachment
    {
        $localPath = $file->getRealPath();

        if (! is_string($localPath) || ! is_file($localPath) || ! is_readable($localPath)) {
            throw ValidationException::withMessages([
                'attachment' => 'No se pudo leer el archivo adjunto.',
            ]);
        }

        $contents = file_get_contents($localPath);

        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages([
                'attachment' => 'El archivo adjunto está vacío o no se pudo leer.',
            ]);
        }

        $originalName = $this->inspector->safeName($file->getClientOriginalName())
            ?? 'archivo';
        $extension = $this->inspector->extensionOf($originalName);

        if ($extension === null) {
            throw ValidationException::withMessages([
                'attachment' => 'El archivo no tiene una extensión válida.',
            ]);
        }

        $kind = $this->inspector->kindForExtension($extension);

        if ($kind === null) {
            throw ValidationException::withMessages([
                'attachment' => 'El formato del archivo no está permitido.',
            ]);
        }

        $detectedMime = $this->inspector->detectMime($contents);

        if ($detectedMime === null) {
            throw ValidationException::withMessages([
                'attachment' => 'No se pudo identificar el tipo real del archivo.',
            ]);
        }

        $allowedMimes = (array) config('attachments.mime.'.$kind, []);

        if (! in_array($detectedMime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'attachment' => 'El contenido real del archivo no coincide con su formato.',
            ]);
        }

        if (! $this->inspector->extensionMatchesMime($extension, $detectedMime)) {
            throw ValidationException::withMessages([
                'attachment' => 'El contenido real del archivo no coincide con su extensión.',
            ]);
        }

        $size = strlen($contents);
        $maxSize = (int) config('attachments.max_size.'.$kind, 0);

        if ($maxSize > 0 && $size > $maxSize) {
            throw ValidationException::withMessages([
                'attachment' => 'El archivo supera el tamaño máximo permitido para su tipo.',
            ]);
        }

        return new OutgoingAttachment(
            kind: $kind,
            originalName: $originalName,
            extension: $extension,
            mimeType: $detectedMime,
            size: $size,
            localPath: $localPath,
            contents: $contents,
        );
    }

    /** Guarda el archivo en el almacenamiento privado configurado. */
    public function store(OutgoingAttachment $attachment): AttachmentStorageResult
    {
        $path = sprintf(
            'agent/%s/%s/%s.%s',
            $attachment->kind,
            now()->format('Y/m'),
            Str::uuid()->toString(),
            $attachment->extension,
        );

        return $this->storage->put($path, $attachment->contents, $attachment->mimeType);
    }

    public function delete(string $path): bool
    {
        return $this->storage->delete($path);
    }

    /**
     * Envía el archivo al chat de Telegram.
     *
     * - GIF -> sendAnimation (conserva la animación).
     * - Imagen JPEG/PNG -> sendPhoto.
     * - WebP -> sendDocument (sendPhoto del Bot API no acepta WebP).
     * - Audio MP3/M4A -> sendAudio; OGG/Opus -> sendDocument por compatibilidad.
     * - Resto -> sendDocument.
     */
    public function send(string $chatId, OutgoingAttachment $attachment, ?string $caption = null): TelegramSendResult
    {
        // Se envía una copia temporal con un nombre que conserva la extensión
        // (el archivo subido por PHP suele llamarse `phpXXXX.tmp`). Así el Bot
        // API recibe un nombre seguro y reconocible y se evita una posible
        // carrera entre la validación y el envío.
        $uploadPath = $this->temporaryUploadPath($attachment);

        if ($uploadPath === null) {
            Log::error('[Adjuntos] No se pudo preparar el archivo temporal para Telegram.', [
                'kind' => $attachment->kind,
            ]);

            return TelegramSendResult::failed();
        }

        try {
            return match (true) {
                $attachment->kind === 'gif' => $this->telegram->sendAnimation($chatId, $uploadPath, $caption),
                $attachment->kind === 'image' && in_array($attachment->mimeType, ['image/jpeg', 'image/png'], true)
                    => $this->telegram->sendPhoto($chatId, $uploadPath, $caption),
                $attachment->kind === 'audio' && in_array($attachment->mimeType, ['audio/mpeg', 'audio/mp4', 'audio/x-m4a'], true)
                    => $this->telegram->sendAudio($chatId, $uploadPath, $caption),
                $attachment->kind === 'video'
                    => $this->telegram->sendVideo($chatId, $uploadPath, $caption),
                default => $this->telegram->sendDocument($chatId, $uploadPath, $caption),
            };
        } finally {
            if (is_file($uploadPath)) {
                @unlink($uploadPath);
            }
        }
    }

    /** Copia el contenido validado a un temporal con nombre y extensión seguros. */
    private function temporaryUploadPath(OutgoingAttachment $attachment): ?string
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment->originalName) ?: 'archivo';
        $safeName = mb_substr($safeName, 0, 120);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agent-'.Str::uuid()->toString().'-'.$safeName;

        $written = @file_put_contents($path, $attachment->contents);

        if ($written === false || $written !== strlen($attachment->contents)) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}

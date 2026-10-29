<?php

namespace App\Services\Attachments;

use App\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\External\Telegram\TelegramAttachmentDescriptor;
use App\Services\External\Telegram\TelegramServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ingesta de adjuntos recibidos por Telegram.
 *
 * Flujo:
 *  1. Se ignoran los tipos no permitidos (sticker) y los formatos no
 *     admitidos por categoría.
 *  2. Se comprueba el tamaño declarado por Telegram contra el límite de la
 *     categoría y contra el límite del Bot API.
 *  3. Se descarga el archivo (`getFile` + descarga), se valida el contenido
 *     real con fileinfo (MIME, extensión) y se comprueba el tamaño real.
 *  4. Se guarda en el almacenamiento privado con un nombre aleatorio.
 *  5. Se registra el adjunto con su estado y, si corresponde, se enlaza al
 *     mensaje del hilo para que el agente lo vea en el panel.
 *
 * Ningún fallo interrumpe la conversación: siempre se registra un estado.
 */
class AttachmentIngestService
{
    public function __construct(
        private readonly TelegramServiceInterface $telegram,
        private readonly AttachmentStorageInterface $storage,
        private readonly AttachmentContentInspector $inspector,
    ) {}

    /**
     * Procesa un adjunto de Telegram y devuelve el registro creado, o null si
     * el tipo no está permitido.
     */
    public function ingest(
        Conversation $conversation,
        ?Message $message,
        TelegramAttachmentDescriptor $descriptor,
    ): ?Attachment {
        $kind = (string) config('attachments.telegram_types.'.$descriptor->type, '');

        if ($kind === '') {
            return null;
        }

        $existing = Attachment::query()
            ->where('channel', 'telegram')
            ->where('telegram_file_id', $descriptor->fileId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $attachment = Attachment::create([
            'message_id' => $message?->id,
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
            'telegram_file_id' => $descriptor->fileId,
            'telegram_file_unique_id' => $descriptor->fileUniqueId,
            'kind' => $kind,
            'original_name' => $this->inspector->safeName($descriptor->fileName),
            'extension' => $this->inspector->extensionOf($descriptor->fileName),
            'mime_type' => $descriptor->mimeType,
            'size' => (int) ($descriptor->fileSize ?? 0),
            'status' => AttachmentStatus::PENDING->value,
        ]);

        return $this->process($attachment, $descriptor, $kind);
    }

    /**
     * Descarga, valida y almacena el adjunto, actualizando su estado.
     */
    private function process(
        Attachment $attachment,
        TelegramAttachmentDescriptor $descriptor,
        string $kind,
    ): Attachment {
        $maxSize = (int) config('attachments.max_size.'.$kind, 0);
        $telegramLimit = (int) config('attachments.telegram_max_download_bytes', 20 * 1024 * 1024);

        // Tamaño declarado por Telegram.
        if ($descriptor->fileSize !== null && $descriptor->fileSize > 0) {
            if ($descriptor->fileSize > $telegramLimit) {
                return $this->mark($attachment, AttachmentStatus::TOO_LARGE, 'El archivo supera el límite de descarga de Telegram (20 MB).');
            }

            if ($maxSize > 0 && $descriptor->fileSize > $maxSize) {
                return $this->mark($attachment, AttachmentStatus::TOO_LARGE, 'El archivo supera el tamaño máximo permitido.');
            }
        }

        $file = $this->telegram->getFile($descriptor->fileId);

        if ($file === null || ! $file->isDownloadable()) {
            return $this->mark($attachment, AttachmentStatus::UNAVAILABLE, 'El archivo ya no está disponible en Telegram.');
        }

        $contents = $this->telegram->downloadFile($file);

        if ($contents === null || $contents === '') {
            return $this->mark($attachment, AttachmentStatus::FAILED, 'No se pudo descargar el archivo de Telegram.');
        }

        $size = strlen($contents);

        if ($size > $telegramLimit) {
            return $this->mark($attachment, AttachmentStatus::TOO_LARGE, 'El archivo supera el límite de descarga de Telegram (20 MB).');
        }

        if ($maxSize > 0 && $size > $maxSize) {
            return $this->mark($attachment, AttachmentStatus::TOO_LARGE, 'El archivo supera el tamaño máximo permitido.');
        }

        $validation = $this->validateContents($contents, $attachment, $kind);

        if ($validation !== null) {
            return $this->mark($attachment, $validation['status'], $validation['message']);
        }

        $path = $this->buildStoragePath($attachment, $kind);

        $stored = $this->storage->put($path, $contents, (string) $attachment->mime_type);

        if (! $stored->stored || $stored->path === null) {
            Log::error('[Adjuntos] No se pudo guardar el archivo.', [
                'attachment_id' => $attachment->id,
                'error' => $stored->error,
            ]);

            return $this->mark($attachment, AttachmentStatus::FAILED, 'No se pudo guardar el archivo en el almacenamiento.');
        }

        $attachment->storage_path = $stored->path;
        $attachment->size = $size;
        $attachment->status = AttachmentStatus::AVAILABLE;
        $attachment->error_message = null;
        $attachment->save();

        return $attachment;
    }

    /**
     * Valida el contenido real del archivo: MIME detectado, extensión y
     * coherencia entre ambos.
     *
     * @return array{status: AttachmentStatus, message: string}|null
     */
    private function validateContents(string $contents, Attachment $attachment, string $kind): ?array
    {
        $allowedExtensions = (array) config('attachments.allowed.'.$kind, []);
        $allowedMimes = (array) config('attachments.mime.'.$kind, []);

        if ($allowedExtensions === []) {
            return ['status' => AttachmentStatus::FAILED, 'message' => 'Tipo de archivo no admitido.'];
        }

        $declaredExtension = $this->inspector->extensionOf($attachment->original_name);

        if ($declaredExtension !== null && ! in_array($declaredExtension, $allowedExtensions, true)) {
            return ['status' => AttachmentStatus::FAILED, 'message' => 'El formato del archivo no está permitido.'];
        }

        $detected = $this->inspector->detectMime($contents);

        if ($detected === null) {
            return ['status' => AttachmentStatus::FAILED, 'message' => 'No se pudo identificar el tipo real del archivo.'];
        }

        if (! in_array($detected, $allowedMimes, true)) {
            return ['status' => AttachmentStatus::FAILED, 'message' => 'El contenido real del archivo no coincide con su formato.'];
        }

        if ($declaredExtension !== null && ! $this->inspector->extensionMatchesMime($declaredExtension, $detected)) {
            return ['status' => AttachmentStatus::FAILED, 'message' => 'El contenido real del archivo no coincide con su extensión.'];
        }

        $attachment->mime_type = $detected;
        $attachment->extension = $declaredExtension ?? $this->inspector->extensionFromMime($detected);

        return null;
    }

    /** Marca el adjunto con un estado final y un mensaje comprensible. */
    private function mark(Attachment $attachment, AttachmentStatus $status, string $message): Attachment
    {
        $attachment->status = $status;
        $attachment->error_message = mb_substr($message, 0, (int) config('attachments.description_max_length', 255));
        $attachment->save();

        return $attachment;
    }

    /**
     * Ruta privada del objeto: `telegram/<kind>/YYYY/MM/<uuid>.<ext>`.
     *
     * El nombre es aleatorio y no incluye datos del paciente, por lo que la
     * ruta no permite adivinar el contenido.
     */
    private function buildStoragePath(Attachment $attachment, string $kind): string
    {
        $extension = strtolower((string) ($attachment->extension ?: 'bin'));
        $name = Str::uuid()->toString().'.'.$extension;

        return sprintf('telegram/%s/%s/%s', $kind, now()->format('Y/m'), $name);
    }
}

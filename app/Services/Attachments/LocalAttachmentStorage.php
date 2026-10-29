<?php

namespace App\Services\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Almacenamiento de respaldo en el disco `local` de Laravel.
 *
 * El disco `local` apunta a `storage/app/private`, fuera del directorio
 * público, por lo que los adjuntos no son accesibles directamente por URL.
 */
class LocalAttachmentStorage implements AttachmentStorageInterface
{
    public function put(string $path, string $contents, string $mimeType): AttachmentStorageResult
    {
        $disk = $this->disk();

        try {
            $written = $disk->put($path, $contents);
        } catch (Throwable $exception) {
            return AttachmentStorageResult::failed('Error al guardar el archivo: '.$exception->getMessage());
        }

        if ($written === false) {
            return AttachmentStorageResult::failed('No se pudo guardar el archivo.');
        }

        return AttachmentStorageResult::stored($path);
    }

    public function get(string $path): ?string
    {
        $disk = $this->disk();

        try {
            if (! $disk->exists($path)) {
                return null;
            }

            return (string) $disk->get($path);
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $path): bool
    {
        try {
            return $this->disk()->delete($path);
        } catch (Throwable $exception) {
            return false;
        }
    }

    public function isAvailable(): bool
    {
        return true;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}

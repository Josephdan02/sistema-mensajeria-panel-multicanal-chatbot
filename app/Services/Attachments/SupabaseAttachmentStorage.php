<?php

namespace App\Services\Attachments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Almacenamiento de adjuntos en Supabase Storage (bucket privado).
 *
 * Se usa la API REST de Storage con la clave `service_role`. La clave solo
 * se usa en el backend: nunca se incluye en las respuestas HTTP del panel,
 * en las URLs generadas ni en los logs.
 *
 * No se añade ningún paquete nuevo: la llamada se hace con el cliente HTTP de
 * Laravel, lo que permite usar `Http::fake()` en las pruebas.
 */
class SupabaseAttachmentStorage implements AttachmentStorageInterface
{
    public function put(string $path, string $contents, string $mimeType): AttachmentStorageResult
    {
        $config = $this->config();

        if ($config === null) {
            return AttachmentStorageResult::failed('Almacenamiento no configurado.');
        }

        $url = $config['url'].'/storage/v1/object/'.rawurlencode($config['bucket']).'/'.self::encodePath($path);

        try {
            $response = Http::timeout($config['timeout'])
                ->withHeaders([
                    'apikey' => $config['key'],
                    'Authorization' => 'Bearer '.$config['key'],
                    'Content-Type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                    'x-upsert' => 'false',
                ])
                ->withBody($contents, $mimeType !== '' ? $mimeType : 'application/octet-stream')
                ->post($url);
        } catch (ConnectionException $exception) {
            Log::error('[Adjuntos] Error de red al guardar en Supabase Storage.', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return AttachmentStorageResult::failed('Error de red al guardar el archivo.');
        }

        if (! $response->successful()) {
            Log::error('[Adjuntos] Supabase Storage rechazó el archivo.', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return AttachmentStorageResult::failed('El almacenamiento rechazó el archivo.');
        }

        return AttachmentStorageResult::stored($path);
    }

    public function get(string $path): ?string
    {
        $config = $this->config();

        if ($config === null) {
            return null;
        }

        $url = $config['url'].'/storage/v1/object/'.rawurlencode($config['bucket']).'/'.self::encodePath($path);

        try {
            $response = Http::timeout($config['timeout'])
                ->withHeaders([
                    'apikey' => $config['key'],
                    'Authorization' => 'Bearer '.$config['key'],
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            Log::error('[Adjuntos] Error de red al leer de Supabase Storage.', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    public function delete(string $path): bool
    {
        $config = $this->config();

        if ($config === null) {
            return false;
        }

        $url = $config['url'].'/storage/v1/object/'.rawurlencode($config['bucket']).'/'.self::encodePath($path);

        try {
            $response = Http::timeout($config['timeout'])
                ->withHeaders([
                    'apikey' => $config['key'],
                    'Authorization' => 'Bearer '.$config['key'],
                ])
                ->delete($url);
        } catch (ConnectionException $exception) {
            Log::error('[Adjuntos] Error de red al eliminar de Supabase Storage.', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        // Un 404 significa que el objeto ya no existe: el resultado es correcto.
        return $response->successful() || $response->status() === 404;
    }

    public function isAvailable(): bool
    {
        return $this->config() !== null;
    }

    /**
     * Configuración de Supabase o null si faltan credenciales.
     *
     * @return array{url: string, key: string, bucket: string, timeout: int}|null
     */
    private function config(): ?array
    {
        $url = rtrim(trim((string) config('services.supabase.url', '')), '/');
        $key = trim((string) config('services.supabase.service_role_key', ''));
        $bucket = trim((string) config('services.supabase.bucket', ''));

        if ($url === '' || $key === '' || $bucket === '') {
            return null;
        }

        return [
            'url' => $url,
            'key' => $key,
            'bucket' => $bucket,
            'timeout' => (int) config('services.supabase.timeout', 30),
        ];
    }

    /** Codifica cada segmento de la ruta conservando las barras. */
    private static function encodePath(string $path): string
    {
        return collect(explode('/', $path))
            ->map(static fn (string $segment): string => rawurlencode($segment))
            ->implode('/');
    }
}

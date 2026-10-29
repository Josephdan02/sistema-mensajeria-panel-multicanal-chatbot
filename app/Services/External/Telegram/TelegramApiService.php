<?php

namespace App\Services\External\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramApiService implements TelegramServiceInterface
{
    /** Límite de caracteres que Telegram acepta en el pie de un archivo. */
    private const CAPTION_LIMIT = 1024;

    public function sendMessage(string $chatId, string $message): TelegramSendResult
    {
        $config = $this->configuration();

        if ($config === null) {
            Log::error('[Telegram] Configuración incompleta: falta bot_token o api_url.', [
                'chat_id' => $chatId,
            ]);

            return TelegramSendResult::failed();
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->acceptJson()
                ->post($config['api_url'].$config['token'].'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::error('[Telegram] Bot API rechazó el mensaje.', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                ]);

                return TelegramSendResult::failed();
            }

            return TelegramSendResult::sent();
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error de red enviando mensaje.', [
                'chat_id' => $chatId,
                'exception' => $this->safeExceptionMessage($exception),
            ]);

            return TelegramSendResult::unknown();
        }
    }

    public function getFile(string $fileId): ?TelegramFile
    {
        $config = $this->configuration();

        if ($config === null || $fileId === '') {
            return null;
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->acceptJson()
                ->get($config['api_url'].$config['token'].'/getFile', ['file_id' => $fileId]);
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error de red obteniendo el archivo.', [
                'file_id' => $fileId,
                'exception' => $this->safeExceptionMessage($exception),
            ]);

            return null;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('[Telegram] El archivo no está disponible.', [
                'file_id' => $fileId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $result = $response->json('result');

        if (! is_array($result)) {
            return null;
        }

        $filePath = is_string($result['file_path'] ?? null) ? $result['file_path'] : null;
        $fileSize = is_int($result['file_size'] ?? null) ? $result['file_size'] : null;
        $fileUniqueId = is_string($result['file_unique_id'] ?? null) ? $result['file_unique_id'] : null;

        return new TelegramFile(
            fileId: $fileId,
            fileUniqueId: $fileUniqueId,
            filePath: $filePath,
            fileSize: $fileSize,
        );
    }

    /**
     * Descarga el binario de un archivo alojado en Telegram.
     *
     * La URL de descarga no comparte la forma de la URL de la Bot API: es
     * `/file/bot<TOKEN>/<file_path>`, no `/bot<TOKEN>/file/...`.
     */
    public function downloadFile(TelegramFile $file): ?string
    {
        $config = $this->configuration();

        if ($config === null || ! $file->isDownloadable()) {
            return null;
        }

        $base = preg_replace('#/bot$#', '', $config['api_url']) ?? $config['api_url'];
        $url = $base.'/file/bot'.$config['token'].'/'.ltrim((string) $file->filePath, '/');

        try {
            $response = Http::timeout($config['timeout'])->get($url);
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error de red descargando el archivo.', [
                'file_id' => $file->fileId,
                'exception' => $this->safeExceptionMessage($exception),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('[Telegram] No se pudo descargar el archivo.', [
                'file_id' => $file->fileId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $contents = $response->body();

        return $contents === '' ? null : $contents;
    }

    public function sendPhoto(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('sendPhoto', 'photo', 'foto', $chatId, $filePath, $caption);
    }

    public function sendDocument(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('sendDocument', 'document', 'documento', $chatId, $filePath, $caption);
    }

    public function sendAudio(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('sendAudio', 'audio', 'audio', $chatId, $filePath, $caption);
    }

    public function sendVideo(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('sendVideo', 'video', 'vídeo', $chatId, $filePath, $caption);
    }

    public function sendAnimation(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('sendAnimation', 'animation', 'animación', $chatId, $filePath, $caption);
    }

    /**
     * Envío común de archivos: multipart con el campo que espera cada método.
     */
    private function sendFile(
        string $method,
        string $field,
        string $label,
        string $chatId,
        string $filePath,
        ?string $caption,
    ): TelegramSendResult {
        $config = $this->configuration();

        if ($config === null) {
            return TelegramSendResult::failed();
        }

        if (! is_file($filePath) || ! is_readable($filePath)) {
            Log::error('[Telegram] No se puede leer el archivo que se intenta enviar.', [
                'chat_id' => $chatId,
                'method' => $method,
            ]);

            return TelegramSendResult::failed();
        }

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            Log::error('[Telegram] Fallo al leer el archivo que se intenta enviar.', [
                'chat_id' => $chatId,
                'method' => $method,
            ]);

            return TelegramSendResult::failed();
        }

        $payload = ['chat_id' => $chatId];
        $caption = $this->normalizedCaption($caption);

        if ($caption !== null) {
            $payload['caption'] = $caption;
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->attach($field, $contents, basename($filePath))
                ->post($config['api_url'].$config['token'].'/'.$method, $payload);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::error('[Telegram] Bot API rechazó el '.$label.'.', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                ]);

                return TelegramSendResult::failed();
            }

            return TelegramSendResult::sent();
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error de red enviando '.$label.'.', [
                'chat_id' => $chatId,
                'exception' => $this->safeExceptionMessage($exception),
            ]);

            return TelegramSendResult::unknown();
        }
    }

    private function normalizedCaption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }

        $caption = trim($caption);

        if ($caption === '') {
            return null;
        }

        return mb_substr($caption, 0, self::CAPTION_LIMIT);
    }

    /**
     * Evita que una excepción de red arrastre el token del bot a los logs.
     * Guzzle puede incluir la URL completa (api.telegram.org/bot<TOKEN>/...).
     */
    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        $message = preg_replace('#(api\.telegram\.org/bot)[A-Za-z0-9:_-]+#i', '$1[REDACTED]', $message) ?? 'Error de red';
        $message = preg_replace('#(file/bot)[A-Za-z0-9:_-]+#i', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('#\b\d{6,}:[A-Za-z0-9_-]{20,}\b#', '[REDACTED]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }

    /**
     * @return array{token: string, api_url: string, timeout: int}|null
     */
    private function configuration(): ?array
    {
        $token = trim((string) config('services.telegram.bot_token', ''));
        $apiUrl = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org/bot'), '/');

        if ($token === '' || $apiUrl === '') {
            return null;
        }

        return [
            'token' => $token,
            'api_url' => $apiUrl,
            'timeout' => (int) config('services.telegram.timeout', 10),
        ];
    }
}

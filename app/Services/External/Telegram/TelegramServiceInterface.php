<?php

namespace App\Services\External\Telegram;

interface TelegramServiceInterface
{
    public function sendMessage(string $chatId, string $message): TelegramSendResult;

    /**
     * Obtiene los metadatos del archivo (ruta de descarga y tamaño) de Telegram.
     */
    public function getFile(string $fileId): ?TelegramFile;

    /**
     * Descarga el contenido binario del archivo de Telegram.
     *
     * @return string|null Contenido del archivo o null si no se pudo descargar.
     */
    public function downloadFile(TelegramFile $file): ?string;

    /**
     * Envía una imagen. `$caption` se adjunta al archivo (máximo 1024 caracteres).
     */
    public function sendPhoto(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult;

    public function sendDocument(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult;

    public function sendAudio(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult;

    public function sendVideo(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult;

    /**
     * Envía un GIF animado (o un MP4 sin sonido) conservando la animación.
     */
    public function sendAnimation(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult;
}

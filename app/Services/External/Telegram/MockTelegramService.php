<?php

namespace App\Services\External\Telegram;

use Illuminate\Support\Facades\Log;

class MockTelegramService implements TelegramServiceInterface
{
    public function sendMessage(string $chatId, string $message): TelegramSendResult
    {
        Log::info('[MockTelegramService] Mensaje simulado enviado', [
            'chat_id' => $chatId,
            'message' => $message,
        ]);

        return TelegramSendResult::sent();
    }

    public function getFile(string $fileId): ?TelegramFile
    {
        Log::info('[MockTelegramService] Metadatos de archivo simulados', [
            'file_id' => $fileId,
        ]);

        return new TelegramFile(
            fileId: $fileId,
            fileUniqueId: 'mock-'.$fileId,
            filePath: 'mock/'.$fileId,
            fileSize: null,
        );
    }

    /**
     * El mock no tiene acceso al contenido real de Telegram. Devuelve null
     * para que la ingesta registre el adjunto como no disponible, sin fingir
     * que se descargó correctamente.
     */
    public function downloadFile(TelegramFile $file): ?string
    {
        Log::info('[MockTelegramService] Descarga de archivo simulada', [
            'file_id' => $file->fileId,
        ]);

        return null;
    }

    public function sendPhoto(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('foto', $chatId, $filePath, $caption);
    }

    public function sendDocument(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('documento', $chatId, $filePath, $caption);
    }

    public function sendAudio(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('audio', $chatId, $filePath, $caption);
    }

    public function sendVideo(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('video', $chatId, $filePath, $caption);
    }

    public function sendAnimation(string $chatId, string $filePath, ?string $caption = null): TelegramSendResult
    {
        return $this->sendFile('animación', $chatId, $filePath, $caption);
    }

    private function sendFile(string $label, string $chatId, string $filePath, ?string $caption): TelegramSendResult
    {
        Log::info('[MockTelegramService] Envío de '.$label.' simulado', [
            'chat_id' => $chatId,
            'file_path' => $filePath,
            'caption' => $caption,
        ]);

        return TelegramSendResult::sent();
    }
}

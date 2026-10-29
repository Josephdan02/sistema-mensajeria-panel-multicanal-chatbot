<?php

namespace App\Services\External\Telegram;

use App\Enums\ConversationState;
use App\Enums\Intent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Attachments\AttachmentIngestService;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramInboundService
{
    public function __construct(
        private readonly ChatbotService $chatbot,
        private readonly TelegramServiceInterface $telegram,
        private readonly AttachmentIngestService $attachments,
        private readonly TelegramAttachmentParser $attachmentParser,
    ) {}

    public function handle(array $payload): int
    {
        $incoming = $this->extractTextMessage($payload);

        if ($incoming === null) {
            return 0;
        }

        if (! Cache::add('telegram:update:'.$incoming['update_id'], 1, 300)) {
            return 0;
        }

        $conversation = $this->resolveConversation($incoming['chat_id']);

        // Mensajes no textuales válidos (fotos, audios, documentos, stickers...):
        // se informa al usuario que solo se procesan mensajes de texto.
        if (($incoming['type'] ?? null) === 'non_text') {
            // Si un agente humano tiene el control de la conversación el bot
            // calla: no se responde al cliente, pero el adjunto queda
            // registrado como mensaje suyo para que el agente lo atienda
            // desde el panel (igual que ocurre con los mensajes de texto).
            if ($conversation->state?->isAgentInCharge() === true) {
                $this->recordNonTextMessageWhileAgentControls(
                    $conversation,
                    $incoming['attachment'] ?? null,
                    $incoming['attachment_descriptor'] ?? null,
                );

                return 1;
            }

            $sendResult = $this->telegram->sendMessage($incoming['chat_id'], 'Entiendo mensajes de texto. Por favor, escribe tu consulta por texto y con gusto te ayudaré.');

            if (! $sendResult->isSent()) {
                Log::warning('[Telegram] No se pudo entregar la respuesta de mensaje no textual.', [
                    'chat_id' => $incoming['chat_id'],
                    'status' => $sendResult->status->value,
                ]);
            }

            return 1;
        }

        $result = $this->chatbot->handle($conversation, $incoming['body']);
        $handledBy = $result['handled_by'] ?? 'bot';
        $reply = (string) ($result['message'] ?? '');

        if ($handledBy === 'bot' && $reply !== '') {
            $sendResult = $this->telegram->sendMessage($incoming['chat_id'], $reply);

            if (! $sendResult->isSent()) {
                Log::warning('[Telegram] No se pudo entregar la respuesta al usuario.', [
                    'chat_id' => $incoming['chat_id'],
                    'status' => $sendResult->status->value,
                ]);
            }
        }

        return 1;
    }

    /**
     * @return array{update_id: string, chat_id: string, body: string}|array{type: string, update_id: string, chat_id: string, attachment: string|null}|null
     */
    private function extractTextMessage(array $payload): ?array
    {
        $updateId = $payload['update_id'] ?? null;
        $message = $payload['message'] ?? null;
        $chatId = is_array($message) ? ($message['chat']['id'] ?? null) : null;
        $text = is_array($message) ? ($message['text'] ?? null) : null;

        if ((! is_int($updateId) && ! is_string($updateId))
            || (! is_int($chatId) && ! is_string($chatId))) {
            return null;
        }

        // Si no hay texto pero hay un mensaje válido de usuario, es un mensaje no textual.
        if (! is_string($text)) {
            if (is_array($message) && $message !== []) {
                Log::info('[Telegram] Update no textual recibido.', [
                    'update_id' => $updateId,
                    'chat_id' => $chatId,
                ]);
            }

            return [
                'type' => 'non_text',
                'update_id' => (string) $updateId,
                'chat_id' => (string) $chatId,
                'attachment' => $this->detectAttachmentType(is_array($message) ? $message : []),
                'attachment_descriptor' => $this->attachmentParser->parse(is_array($message) ? $message : []),
            ];
        }

        $body = trim($text);

        if ($body === '') {
            return null;
        }

        // Normalizar comandos de Telegram con posible sufijo @nombre_del_bot.
        if (preg_match('/^\/(\w+)(?:@\w+)?$/u', $body, $matches) === 1) {
            $command = mb_strtolower($matches[1]);
            $body = match ($command) {
                'start' => 'hola',
                'help' => 'ayuda',
                'menu' => 'menú',
                default => $body,
            };
        }

        return [
            'update_id' => (string) $updateId,
            'chat_id' => (string) $chatId,
            'body' => $body,
        ];
    }

    private function resolveConversation(string $chatId): Conversation
    {
        $conversation = Conversation::query()
            ->where('user_identifier', $chatId)
            ->where('channel', 'telegram')
            ->latest('id')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return Conversation::create([
            'user_identifier' => $chatId,
            'channel' => 'telegram',
            'state' => ConversationState::IDLE,
        ]);
    }

    /**
     * Registra un mensaje no textual del cliente (foto, audio, documento...)
     * mientras un agente humano tiene el control de la conversación.
     *
     * El bot no responde (para no interferir con la atención humana), pero el
     * adjunto queda persistido como mensaje del cliente y sin leer, de modo
     * que el agente lo ve en el hilo y en el contador de pendientes.
     *
     * El archivo se descarga de Telegram y se guarda en el almacenamiento
     * privado; si algo falla, el adjunto queda con su estado de error y el
     * hilo sigue funcionando.
     */
    private function recordNonTextMessageWhileAgentControls(
        Conversation $conversation,
        ?string $attachment = null,
        ?TelegramAttachmentDescriptor $descriptor = null,
    ): void {
        $conversation->last_message_at = now();
        $conversation->save();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_USER,
            'message' => $this->describeNonTextMessage($attachment),
            'intent' => Intent::HUMAN_HANDOFF->value,
        ]);

        if ($descriptor !== null) {
            $this->attachments->ingest($conversation, $message, $descriptor);
        }
    }

    /**
     * Detecta el tipo de adjunto recibido para que el agente sepa qué envió
     * el cliente (foto, audio, vídeo, documento o sticker).
     */
    private function detectAttachmentType(array $message): ?string
    {
        foreach (['photo', 'audio', 'video', 'document', 'sticker', 'voice', 'animation'] as $type) {
            if (array_key_exists($type, $message)) {
                return $type;
            }
        }

        return null;
    }

    /** Descripción legible del adjunto recibido, visible en el panel de agentes. */
    private function describeNonTextMessage(?string $attachment): string
    {
        $labels = [
            'photo' => 'una foto',
            'audio' => 'un audio',
            'voice' => 'una nota de voz',
            'video' => 'un vídeo',
            'animation' => 'un GIF',
            'document' => 'un documento',
            'sticker' => 'un sticker',
        ];

        $label = $attachment !== null ? ($labels[$attachment] ?? 'un archivo') : 'un archivo o imagen';

        return 'El cliente envió '.$label.' por Telegram.';
    }
}

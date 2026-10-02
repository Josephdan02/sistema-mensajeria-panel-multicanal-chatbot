<?php

namespace App\Services\External\WhatsApp;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Services\Chatbot\ChatbotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Puente WhatsApp entrante -> sistema existente.
 *
 * Esta es la ÚNICA pieza que conoce ambos mundos: recibe el payload ya
 * validado del webhook, resuelve/crea la Conversation (canal 'whatsapp'),
 * delega en ChatbotService exactamente igual que hace /api/chat, y decide si
 * la respuesta se envía por Cloud API según el resultado:
 *
 *   - handled_by ausente/'bot' y mensaje no vacío -> WhatsAppServiceInterface.
 *   - handled_by 'agent' (WAITING_AGENT/ASSIGNED/ATTENDING) -> silencio total.
 *
 * ChatbotService no se modifica ni conoce WhatsApp: si mañana cambia la
 * lógica del bot, solo se toca este adaptador.
 */
class WhatsAppInboundService
{
    public function __construct(
        private readonly ChatbotService $chatbot,
        private readonly WhatsAppServiceInterface $whatsapp,
    ) {
    }

    /**
     * Procesa un payload de webhook de Meta y devuelve cuántos mensajes de
     * usuario se procesaron (0 = nada que hacer: statuses, no-texto, dups).
     */
    public function handle(array $payload): int
    {
        $processed = 0;

        foreach ($this->extractTextMessages($payload) as $incoming) {
            if ($this->processMessage($incoming['from'], $incoming['id'], $incoming['body'])) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Recorre entry[].changes[].value.messages[] y se queda solo con los de
     * tipo texto. Los mensajes adjuntos (image/audio/document/sticker/...) y
     * los statuses (entregados/leídos) se ignoran sin romper el webhook.
     *
     * @return array<int, array{from: string, id: string, body: string}>
     */
    private function extractTextMessages(array $payload): array
    {
        $messages = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                foreach (($value['messages'] ?? []) as $message) {
                    $from = (string) ($message['from'] ?? '');
                    $id = (string) ($message['id'] ?? '');
                    $type = (string) ($message['type'] ?? '');

                    if ($from === '' || $id === '') {
                        continue;
                    }

                    if ($type !== 'text') {
                        Log::info('[WhatsApp] Mensaje no textual ignorado.', [
                            'from' => $from,
                            'id' => $id,
                            'type' => $type,
                        ]);

                        continue;
                    }

                    $body = trim((string) ($message['text']['body'] ?? ''));

                    if ($body === '') {
                        continue;
                    }

                    $messages[] = ['from' => $from, 'id' => $id, 'body' => $body];
                }
            }
        }

        return $messages;
    }

    private function processMessage(string $from, string $wamid, string $body): bool
    {
        // Idempotencia: Meta reintenta el POST si no responde 200 rápido.
        // Cache::add es atómico: solo un request gana el procesamiento.
        // TTL de 24 h: los reintentos de Meta ocurren en minutos.
        if (! Cache::add('whatsapp:message:'.$wamid, 1, 86400)) {
            return false;
        }

        $conversation = $this->resolveConversation($from);

        $result = $this->chatbot->handle($conversation, $body);

        $handledBy = $result['handled_by'] ?? 'bot';
        $reply = (string) ($result['message'] ?? '');

        if ($handledBy !== 'bot' || $reply === '') {
            // El bot se calló (cola o agente controlando): el mensaje ya
            // quedó persistido y el agente lo verá en el panel.
            return true;
        }

        $this->whatsapp->sendMessage($from, $reply);

        return true;
    }

    /**
     * Conversación del número en el canal whatsapp: se reutiliza la última
     * del mismo número+canal o se crea con estado IDLE (bot controlando).
     */
    private function resolveConversation(string $from): Conversation
    {
        // Meta envía el número en E.164 sin '+'; se normaliza para que el
        // identificador sea estable entre mensajes.
        $identifier = ltrim(preg_replace('/[^0-9]/', '', $from), '+');

        $conversation = Conversation::query()
            ->where('user_identifier', $identifier)
            ->where('channel', 'whatsapp')
            ->latest('id')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return Conversation::create([
            'user_identifier' => $identifier,
            'channel' => 'whatsapp',
            'state' => ConversationState::IDLE,
        ]);
    }
}
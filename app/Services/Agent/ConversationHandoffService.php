<?php

namespace App\Services\Agent;

use App\Enums\AttachmentStatus;
use App\Enums\ConversationState;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Attachments\OutgoingAttachmentService;
use App\Services\External\Telegram\TelegramServiceInterface;
use App\Services\External\Telegram\TelegramSendResult;
use App\Services\External\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handoff BOT -> HUMANO del lado del agente.
 *
 * Movimientos de estado y mensajes del agente. Es un servicio aditivo: no
 * modifica la lógica del chatbot (ChatbotService), solo opera sobre los estados
 * del sistema de agentes y persiste mensajes con sender=agent / sender=system.
 *
 * Canal: cuando la conversación es de WhatsApp (channel='whatsapp'), la
 * respuesta del agente también se envía al número del cliente vía
 * WhatsAppServiceInterface. Es el único punto de salida al canal desde el
 * panel; un fallo de envío se loguea pero nunca rompe la respuesta del agente.
 */
class ConversationHandoffService
{
    public function __construct(
        private readonly WhatsAppServiceInterface $whatsapp,
        private readonly TelegramServiceInterface $telegram,
        private readonly OutgoingAttachmentService $attachments,
    ) {
    }

    /** El agente toma la conversación (de la cola o de otro agente). */
    public function claim(Conversation $conversation, User $agent): Conversation
    {
        $wasUnassigned = $conversation->assigned_agent_id === null;

        if ($conversation->assigned_agent_id !== $agent->id) {
            $conversation->assigned_agent_id = $agent->id;
            $conversation->assigned_at = now();
        }

        $conversation->state = ConversationState::ASSIGNED;
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save();

        $this->markAsRead($conversation);

        $this->systemMessage(
            $conversation,
            $wasUnassigned
                ? sprintf('%s tomó la conversación.', $agent->name)
                : sprintf('La conversación quedó asignada a %s.', $agent->name),
        );

        return $conversation->refresh();
    }

    /** Asigna la conversación a otro agente (o a sí mismo). */
    public function assign(Conversation $conversation, User $target, User $by): Conversation
    {
        $conversation->assigned_agent_id = $target->id;
        $conversation->assigned_at = now();

        if ($conversation->state->isWaitingForAgent() || $conversation->state->isClosed()) {
            $conversation->state = ConversationState::ASSIGNED;
        }

        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save();

        $this->systemMessage(
            $conversation,
            sprintf('%s asignó la conversación a %s.', $by->name, $target->name),
        );

        return $conversation->refresh();
    }

    /** Marca la conversación como "en atención". */
    public function startAttending(Conversation $conversation, User $agent): Conversation
    {
        if ($conversation->assigned_agent_id === null) {
            $conversation->assigned_agent_id = $agent->id;
            $conversation->assigned_at = now();
        }

        $conversation->state = ConversationState::ATTENDING;
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save();

        $conversation->refresh();

        $this->systemMessage($conversation, sprintf('%s está atendiendo la conversación.', $agent->name));

        return $conversation->refresh();
    }

    /**
     * Respuesta del agente: se persiste con sender=agent + agent_id y la
     * conversación queda EN ATENCIÓN (no vuelve al bot automáticamente).
     */
    public function reply(Conversation $conversation, User $agent, string $body): Message|TelegramSendResult|null
    {
        $telegramResult = $this->sendToTelegramIfChannel($conversation, $body);

        if ($telegramResult !== null && ! $telegramResult->isSent()) {
            return $telegramResult;
        }

        if ($conversation->assigned_agent_id === null) {
            $conversation->assigned_agent_id = $agent->id;
            $conversation->assigned_at = now();
        }

        $conversation->state = ConversationState::ATTENDING;
        $conversation->last_message_at = now();
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_AGENT,
            'message' => $body,
            'intent' => null,
            'agent_id' => $agent->id,
            'read_at' => now(),
        ]);

        $this->markAsRead($conversation);

        // Canal WhatsApp: la respuesta del agente sale también al número del
        // cliente. En canal web no se hace nada (el panel ya la muestra).
        $this->sendToWhatsAppIfChannel($conversation, $body);

        return $message;
    }

    /**
     * Respuesta del agente con un archivo adjunto.
     *
     * El archivo se valida y se guarda en el almacenamiento privado antes de
     * enviarlo. Si Telegram lo rechaza (o el resultado es ambiguo), se elimina
     * el objeto guardado y NO se persiste el mensaje. El texto se envía como
     * pie del archivo cuando cabe en el límite del Bot API (1024 caracteres);
     * si es más largo, se envía como mensaje independiente.
     */
    public function replyWithAttachment(
        Conversation $conversation,
        User $agent,
        string $body,
        UploadedFile $file,
    ): Message|TelegramSendResult {
        $outgoing = $this->attachments->prepare($file);

        if (! in_array($conversation->channel, ['telegram', 'web'], true)) {
            Log::warning('[Adjuntos] Canal sin soporte de adjuntos salientes.', [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
            ]);

            return TelegramSendResult::failed();
        }

        $stored = $this->attachments->store($outgoing);

        if (! $stored->stored || $stored->path === null) {
            Log::error('[Adjuntos] No se pudo guardar el archivo saliente.', [
                'conversation_id' => $conversation->id,
                'error' => $stored->error,
            ]);

            return TelegramSendResult::failed();
        }

        $storedPath = $stored->path;
        $caption = null;
        $sendTextSeparately = false;

        if (mb_strlen($body) > 0) {
            if (mb_strlen($body) <= 1024) {
                $caption = $body;
            } else {
                $sendTextSeparately = true;
            }
        }

        try {
            if ($conversation->channel === 'telegram') {
                $fileResult = $this->attachments->send(
                    (string) $conversation->user_identifier,
                    $outgoing,
                    $caption,
                );

                if (! $fileResult->isSent()) {
                    $this->attachments->delete($storedPath);

                    Log::warning('[Telegram] No se pudo enviar el archivo del agente.', [
                        'conversation_id' => $conversation->id,
                        'status' => $fileResult->status->value,
                    ]);

                    return $fileResult;
                }

                if ($sendTextSeparately) {
                    $textResult = $this->telegram->sendMessage(
                        (string) $conversation->user_identifier,
                        $body,
                    );

                    if (! $textResult->isSent()) {
                        $this->attachments->delete($storedPath);

                        Log::warning('[Telegram] No se pudo enviar el texto que acompaña al archivo.', [
                            'conversation_id' => $conversation->id,
                            'status' => $textResult->status->value,
                        ]);

                        return $textResult;
                    }
                }
            }

            $message = DB::transaction(function () use ($conversation, $agent, $body, $outgoing, $storedPath): Message {
                if ($conversation->assigned_agent_id === null) {
                    $conversation->assigned_agent_id = $agent->id;
                    $conversation->assigned_at = now();
                }

                $conversation->state = ConversationState::ATTENDING;
                $conversation->last_message_at = now();
                $conversation->closed_at = null;
                $conversation->closed_by = null;
                $conversation->save();

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender' => Message::SENDER_AGENT,
                    'message' => $body,
                    'intent' => null,
                    'agent_id' => $agent->id,
                    'read_at' => now(),
                ]);

                Attachment::create([
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'channel' => $conversation->channel,
                    'kind' => $outgoing->kind,
                    'original_name' => $outgoing->originalName,
                    'extension' => $outgoing->extension,
                    'mime_type' => $outgoing->mimeType,
                    'size' => $outgoing->size,
                    'status' => AttachmentStatus::AVAILABLE->value,
                    'storage_path' => $storedPath,
                ]);

                $this->markAsRead($conversation);

                return $message;
            });
        } catch (Throwable $exception) {
            $this->attachments->delete($storedPath);

            Log::error('[Adjuntos] Error persistiendo el archivo saliente.', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $message;
    }

    private function sendToTelegramIfChannel(Conversation $conversation, string $body): ?TelegramSendResult
    {
        if ($conversation->channel !== 'telegram') {
            return null;
        }

        try {
            $result = $this->telegram->sendMessage((string) $conversation->user_identifier, $body);

            if (! $result->isSent()) {
                Log::warning('[Telegram] No se pudo enviar la respuesta del agente.', [
                    'conversation_id' => $conversation->id,
                    'to' => $conversation->user_identifier,
                    'status' => $result->status->value,
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            Log::error('[Telegram] Error inesperado enviando respuesta del agente.', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
            ]);

            return TelegramSendResult::unknown();
        }
    }

    /**
     * Envía la respuesta del agente por WhatsApp solo si la conversación es
     * de ese canal. Cualquier error se registra y se continúa: el mensaje ya
     * está persistido y el agente no debe ver fallar su respuesta por una
     * falla transitoria de la API de Meta.
     */
    private function sendToWhatsAppIfChannel(Conversation $conversation, string $body): void
    {
        if ($conversation->channel !== 'whatsapp') {
            return;
        }

        try {
            $sent = $this->whatsapp->sendMessage((string) $conversation->user_identifier, $body);

            if (! $sent) {
                Log::warning('[WhatsApp] No se pudo enviar la respuesta del agente.', [
                    'conversation_id' => $conversation->id,
                    'to' => $conversation->user_identifier,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('[WhatsApp] Error inesperado enviando respuesta del agente.', [
                'conversation_id' => $conversation->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** Cambio manual de estado desde el panel. */
    public function changeState(
        Conversation $conversation,
        ConversationState $state,
        User $agent,
        ?string $note = null,
    ): Conversation {
        $conversation->state = $state;

        if ($state->isHumanControlled() && $conversation->assigned_agent_id === null) {
            $conversation->assigned_agent_id = $agent->id;
            $conversation->assigned_at = now();
        }

        if ($state->isWaitingForAgent()) {
            $conversation->assigned_agent_id = null;
            $conversation->assigned_at = null;
        }

        if ($state->isClosed()) {
            $conversation->closed_at = now();
            $conversation->closed_by = $agent->id;
        } else {
            $conversation->closed_at = null;
            $conversation->closed_by = null;
        }

        $conversation->save();

        $this->systemMessage($conversation, sprintf(
            '%s cambió el estado a «%s».%s',
            $agent->name,
            $state->label(),
            is_string($note) && trim($note) !== '' ? ' '.trim($note) : '',
        ));

        return $conversation->refresh();
    }

    public function close(Conversation $conversation, User $agent): Conversation
    {
        $conversation->state = ConversationState::CLOSED;
        $conversation->closed_at = now();
        $conversation->closed_by = $agent->id;
        $conversation->clearContext();
        $conversation->save();

        $this->systemMessage($conversation, sprintf('%s cerró la conversación.', $agent->name));

        return $conversation->refresh();
    }

    public function hasUncertainAppointmentOperation(Conversation $conversation): bool
    {
        $slots = $conversation->getContextValue('slots', []);

        if (! is_array($slots)) {
            return false;
        }

        return ($slots['appointment_creation_status'] ?? null) === 'unknown'
            || ($slots['appointment_operation_status'] ?? null) === 'unknown';
    }

    /**
     * Devuelve el control al bot solo cuando no hay una operación de agenda
     * cuyo resultado deba verificarse en CABANILLAS.
     */
    public function releaseToBot(Conversation $conversation, User $agent): ?Conversation
    {
        if ($this->hasUncertainAppointmentOperation($conversation)) {
            return null;
        }

        $conversation->state = ConversationState::IDLE;
        $conversation->assigned_agent_id = null;
        $conversation->assigned_at = null;
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->clearContext();
        $conversation->save();

        $this->systemMessage($conversation, sprintf('%s devolvió la conversación al bot.', $agent->name));

        return $conversation->refresh();
    }

    /** Devuelve la conversación a la cola de espera de agentes. */
    public function returnToQueue(Conversation $conversation, User $agent): Conversation
    {
        $conversation->state = ConversationState::WAITING_AGENT;
        $conversation->assigned_agent_id = null;
        $conversation->assigned_at = null;
        $conversation->closed_at = null;
        $conversation->closed_by = null;
        $conversation->save();

        $this->systemMessage($conversation, sprintf('%s devolvió la conversación a la cola de agentes.', $agent->name));

        return $conversation->refresh();
    }

    /** Marca como leídos los mensajes del cliente pendientes. */
    public function markAsRead(Conversation $conversation): int
    {
        return $conversation->messages()
            ->where('sender', Message::SENDER_USER)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function systemMessage(Conversation $conversation, string $text): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender' => Message::SENDER_SYSTEM,
            'message' => $text,
            'intent' => null,
            'read_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }
}

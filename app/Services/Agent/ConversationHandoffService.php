<?php

namespace App\Services\Agent;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\External\WhatsApp\WhatsAppServiceInterface;
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
    public function __construct(private readonly WhatsAppServiceInterface $whatsapp)
    {
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
    public function reply(Conversation $conversation, User $agent, string $body): Message
    {
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

    /**
     * Devuelve el control al bot: la conversación volverá a responder
     * automáticamente en el siguiente mensaje del cliente.
     */
    public function releaseToBot(Conversation $conversation, User $agent): Conversation
    {
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

<?php

namespace App\Enums;

enum ConversationState: string
{
    case IDLE = 'idle';
    case IN_PROGRESS = 'in_progress';
    case HANDOFF_TO_HUMAN = 'handoff_to_human';
    case CLOSED = 'closed';

    // ---------------------------------------------------------------------
    // Estados del sistema de agentes (aditivos). Los cuatro estados
    // anteriores mantienen su valor original para no romper conversaciones
    // existentes ni pruebas.
    // ---------------------------------------------------------------------
    case WAITING_AGENT = 'waiting_agent';

    case ASSIGNED = 'assigned';

    case ATTENDING = 'attending';

    public function label(): string
    {
        return match ($this) {
            self::IDLE => 'Bot activo',
            self::IN_PROGRESS => 'Bot en conversación',
            self::HANDOFF_TO_HUMAN => 'Esperando agente',
            self::WAITING_AGENT => 'Esperando agente',
            self::ASSIGNED => 'Asignada',
            self::ATTENDING => 'En atención',
            self::CLOSED => 'Cerrada',
        };
    }

    /** El bot gestiona la conversación automáticamente. */
    public function isBotControlled(): bool
    {
        return in_array($this, [self::IDLE, self::IN_PROGRESS], true);
    }

    /** La conversación está en cola esperando un agente humano. */
    public function isWaitingForAgent(): bool
    {
        return in_array($this, [self::HANDOFF_TO_HUMAN, self::WAITING_AGENT], true);
    }

    /** Un agente humano tiene el control: el bot no debe responder. */
    public function isHumanControlled(): bool
    {
        return in_array($this, [self::ASSIGNED, self::ATTENDING], true);
    }

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }

    /**
     * Estados que el panel de agentes permite fijar manualmente.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return [self::WAITING_AGENT, self::ASSIGNED, self::ATTENDING, self::CLOSED];
    }

    /**
     * @return array<int, string>
     */
    public static function selectableValues(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::selectable());
    }

}

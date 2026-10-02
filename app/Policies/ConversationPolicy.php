<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Reglas de acceso al centro de agentes.
 *
 * Laravel la resuelve automáticamente para App\Models\Conversation, por lo que
 * los controladores usan Gate::authorize('take', $conversation), etc.
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAgentPanel();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->canAccessAgentPanel();
    }

    public function take(User $user, Conversation $conversation): bool
    {
        return $user->canAccessAgentPanel();
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $user->canAccessAgentPanel();
    }

    public function updateState(User $user, Conversation $conversation): bool
    {
        return $user->canAccessAgentPanel();
    }

    public function close(User $user, Conversation $conversation): bool
    {
        return $user->canAccessAgentPanel();
    }

    /**
     * Solo un supervisor puede reasignar una conversación que ya tiene agente;
     * cualquier agente puede asignarse a sí mismo o tomar una sin asignar.
     */
    public function assign(User $user, Conversation $conversation): bool
    {
        if (! $user->canAccessAgentPanel()) {
            return false;
        }

        return $user->isSupervisor()
            || $conversation->assigned_agent_id === null
            || $conversation->assigned_agent_id === $user->id;
    }
}

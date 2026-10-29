<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Acceso a los adjuntos de los canales externos.
 *
 * Un adjunto solo es visible para un agente que puede ver la conversación a
 * la que pertenece. Los adjuntos son datos clínicos: no se exponen por URL
 * ni se comparten fuera de la conversación autorizada.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if (! $user->canAccessAgentPanel()) {
            return false;
        }

        $conversation = $attachment->conversation;

        if ($conversation === null) {
            return false;
        }

        // El adjunto pertenece a una conversación: se exige que el agente
        // pueda atenderla. Un supervisor tiene acceso a todas y un agente solo
        // a las conversaciones asignadas a él o a las que están sin asignar
        // (cola de espera), que puede tomar.
        if ($user->isSupervisor()) {
            return true;
        }

        return $conversation->assigned_agent_id === null
            || $conversation->assigned_agent_id === $user->id;
    }

    public function download(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }
}

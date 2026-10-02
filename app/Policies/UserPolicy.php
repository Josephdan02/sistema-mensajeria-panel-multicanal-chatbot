<?php

namespace App\Policies;

use App\Models\User;

/**
 * Administración de usuarios del centro de agentes.
 *
 * Solo supervisores activos pueden gestionar usuarios. Se protege además al
 * usuario autenticado: nadie puede degradar ni desactivar su propia cuenta
 * desde esta pantalla (evita dejar el sistema sin supervisor por error).
 */
class UserPolicy
{
    public function manageUsers(User $user): bool
    {
        return $user->isSupervisor() && $user->isActive();
    }

    public function manage(User $user, User $managed): bool
    {
        if (! $this->manageUsers($user)) {
            return false;
        }

        // Solo usuarios del sistema de agentes; no tocar cuentas externas.
        if (! in_array($managed->role, User::AGENT_ROLES, true)) {
            return false;
        }

        return true;
    }
}

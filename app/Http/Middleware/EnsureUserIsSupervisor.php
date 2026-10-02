<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el acceso a la administración de usuarios (/agent/users, /agent/settings).
 *
 * Solo supervisores activos. Se usa DESPUÉS del middleware `agent`, que ya
 * garantiza sesión válida, rol de agente y marca de actividad.
 */
class EnsureUserIsSupervisor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSupervisor() || ! $user->isActive()) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Acceso restringido a supervisores.'], 403)
                : redirect()->route('agent.dashboard')->withErrors([
                    'access' => 'Solo un supervisor puede administrar usuarios.',
                ]);
        }

        return $next($request);
    }
}

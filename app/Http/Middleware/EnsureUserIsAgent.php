<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el acceso al centro de agentes (/agent/*).
 *
 * Un usuario puede entrar solo si tiene rol de agente/supervisor y está activo.
 * Los usuarios existentes (role = null) no se ven afectados: simplemente no
 * tienen acceso al panel.
 */
class EnsureUserIsAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'No autenticado.'], 401)
                : redirect()->guest(route('agent.login'));
        }

        if (! $user->canAccessAgentPanel()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Tu usuario no tiene acceso al panel de agentes.'], 403)
                : redirect()->route('agent.login')->withErrors([
                    'email' => 'Tu usuario no tiene acceso al panel de agentes.',
                ]);
        }

        // Marca de actividad del agente (se escribe como máximo una vez por minuto).
        if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->save();
        }

        return $next($request);
    }
}

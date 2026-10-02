<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Autenticación de agentes usando el guard `web` existente.
 *
 * No se añade ningún starter kit: se reutiliza el modelo User + sesión web.
 */
class LoginController extends Controller
{
    public function show(Request $request): View
    {
        // Un agente ya autenticado no necesita volver al formulario.
        if ($request->user() !== null && $request->user()->canAccessAgentPanel()) {
            return view('agent.auth.login', ['alreadyAuthenticated' => true]);
        }

        return view('agent.auth.login', ['alreadyAuthenticated' => false]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        // Si hay otra sesión abierta (p. ej. un usuario sin rol de agente) se
        // cierra antes de autenticar al agente.
        if ($request->user() !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if (! Auth::guard('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $user = Auth::guard('web')->user();

        if ($user === null || ! $user->canAccessAgentPanel()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Tu usuario no tiene acceso al panel de agentes.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_seen_at' => now()])->save();

        return redirect()->intended(route('agent.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('agent.login')->with('status', 'Sesión cerrada correctamente.');
    }
}

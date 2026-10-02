<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreUserRequest;
use App\Http\Requests\Agent\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Administración de usuarios del centro de agentes.
 *
 * Exclusiva para supervisores (middleware `supervisor` en routes/agent.php).
 * Reutiliza el modelo User existente: no hay tabla paralela de agentes.
 *
 * Salvaguardas: nadie puede quitarse a sí mismo el rol de supervisor,
 * desactivarse a sí mismo ni desactivar al último supervisor activo.
 */
class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manageUsers', User::class);

        $users = User::query()
            ->agents()
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('agent.users.index', ['users' => $users]);
    }

    public function create(): View
    {
        Gate::authorize('manageUsers', User::class);

        return view('agent.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        Gate::authorize('manageUsers', User::class);

        User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'role' => $request->string('role')->toString(),
            'active' => $request->boolean('active', true),
        ]);

        return redirect()->route('agent.users.index')->with('status', 'Agente creado correctamente.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('manage', $user);

        return view('agent.users.edit', ['managed' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $data = [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $request->string('role')->toString(),
        ];

        // La activación/desactivación tiene salvaguardas propias (ver request).
        if ($request->has('active')) {
            $data['active'] = $request->boolean('active');
        }

        $user->update($data);

        return redirect()->route('agent.users.index')->with('status', 'Usuario actualizado correctamente.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $me = request()->user();

        if ($me !== null && $user->is($me) && $user->active) {
            return back()->withErrors(['active' => 'No puedes desactivar tu propio usuario.']);
        }

        if ($user->active && $user->isSupervisor() && ! self::anotherActiveSupervisorExists($user)) {
            return back()->withErrors(['active' => 'No puedes desactivar al último supervisor activo.']);
        }

        $user->update(['active' => ! $user->active]);

        return back()->with('status', $user->active ? 'Usuario desactivado.' : 'Usuario activado.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        // Contraseña temporal segura: el usuario la cambia luego en /agent/profile.
        $temporary = bin2hex(random_bytes(8));

        $user->update(['password' => $temporary]);

        return back()->with('status', 'Contraseña restablecida a: '.$temporary.' (comunícala al usuario para que la cambie en su perfil).');
    }

    public static function anotherActiveSupervisorExists(User $except): bool
    {
        return User::query()
            ->where('role', User::ROLE_SUPERVISOR)
            ->where('active', true)
            ->where('id', '!=', $except->id)
            ->exists();
    }
}

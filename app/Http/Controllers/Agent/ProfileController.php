<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdatePasswordRequest;
use App\Http\Requests\Agent\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('agent.profile', [
            'agent' => $request->user(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()->route('agent.profile.show')->with('status', 'Perfil actualizado correctamente.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        // El cast `password => hashed` del modelo se encarga del hash.
        $request->user()->update(['password' => $request->string('password')->toString()]);

        return redirect()->route('agent.profile.show')->with('status', 'Contraseña actualizada correctamente.');
    }
}

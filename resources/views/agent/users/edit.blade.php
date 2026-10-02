@extends('agent.layouts.app')

@section('title', 'Editar usuario')

@section('content')
    <div class="page page--narrow">
        <header class="page__head">
            <h1>Editar usuario</h1>
            <p class="muted">{{ $managed->roleLabel() }} · Última actividad: {{ $managed->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</p>
        </header>

        <section class="panel">
            <form method="POST" action="{{ route('agent.users.update', $managed) }}" class="form">
                @csrf
                @method('PUT')

                <label for="name">Nombre</label>
                <input id="name" name="name" type="text" value="{{ old('name', $managed->name) }}" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="email">Correo</label>
                <input id="email" name="email" type="email" value="{{ old('email', $managed->email) }}" required>
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="role">Rol</label>
                <select id="role" name="role" required>
                    <option value="{{ \App\Models\User::ROLE_AGENT }}" @selected(old('role', $managed->role) === \App\Models\User::ROLE_AGENT)>Agente</option>
                    <option value="{{ \App\Models\User::ROLE_SUPERVISOR }}" @selected(old('role', $managed->role) === \App\Models\User::ROLE_SUPERVISOR)>Supervisor</option>
                </select>
                @error('role')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label class="check">
                    <input type="checkbox" name="active" value="1" @checked(old('active', $managed->active))>
                    <span>Usuario activo</span>
                </label>
                <p class="muted muted--sm">Desmarcar equivale a desactivar el acceso a <code>/agent</code> sin borrar al usuario.</p>
                @error('active')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <p class="muted muted--sm">Para cambiar la contraseña de este usuario usa «Restablecer contraseña» desde el listado: genera una temporal que el usuario cambia luego en su perfil.</p>

                <div class="form__actions">
                    <a class="btn btn--sm" href="{{ route('agent.users.index') }}">Cancelar</a>
                    <button type="submit" class="btn btn--primary btn--sm">Guardar cambios</button>
                </div>
            </form>
        </section>
    </div>
@endsection

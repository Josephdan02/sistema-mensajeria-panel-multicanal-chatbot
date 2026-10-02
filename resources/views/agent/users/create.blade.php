@extends('agent.layouts.app')

@section('title', 'Crear agente')

@section('content')
    <div class="page page--narrow">
        <header class="page__head">
            <h1>Crear agente</h1>
            <p class="muted">El nuevo usuario podrá entrar a <code>/agent/login</code> si queda activo.</p>
        </header>

        <section class="panel">
            <form method="POST" action="{{ route('agent.users.store') }}" class="form">
                @csrf

                <label for="name">Nombre</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="email">Correo</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="role">Rol</label>
                <select id="role" name="role" required>
                    <option value="{{ \App\Models\User::ROLE_AGENT }}" @selected(old('role', \App\Models\User::ROLE_AGENT) === \App\Models\User::ROLE_AGENT)>Agente</option>
                    <option value="{{ \App\Models\User::ROLE_SUPERVISOR }}" @selected(old('role') === \App\Models\User::ROLE_SUPERVISOR)>Supervisor</option>
                </select>
                @error('role')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="password">Contraseña inicial</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" minlength="8">
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <label for="password_confirmation">Confirmar contraseña</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

                <label class="check">
                    <input type="checkbox" name="active" value="1" @checked(old('active', true))>
                    <span>Usuario activo</span>
                </label>
                @error('active')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <div class="form__actions">
                    <a class="btn btn--sm" href="{{ route('agent.users.index') }}">Cancelar</a>
                    <button type="submit" class="btn btn--primary btn--sm">Crear usuario</button>
                </div>
            </form>
        </section>
    </div>
@endsection

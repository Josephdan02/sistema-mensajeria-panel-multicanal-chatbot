@extends('agent.layouts.app')

@section('title', 'Mi perfil')

@section('content')
    <div class="page">
        <header class="page__head">
            <h1>Mi perfil</h1>
            <p class="muted">
                {{ $agent->roleLabel() }} · Última actividad:
                {{ $agent->last_seen_at?->format('d/m/Y H:i') ?? '—' }}
            </p>
        </header>

        <div class="two-col">
            <section class="panel">
                <header class="panel__head">
                    <h2>Datos de la cuenta</h2>
                </header>

                <form method="POST" action="{{ route('agent.profile.update') }}" class="form">
                    @csrf
                    @method('PUT')

                    <label for="name">Nombre</label>
                    <input id="name" name="name" type="text" value="{{ old('name', $agent->name) }}" required>
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror

                    <label for="email">Correo</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $agent->email) }}" required>
                    @error('email')
                        <p class="field-error">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn btn--primary">Guardar cambios</button>
                </form>
            </section>

            <section class="panel">
                <header class="panel__head">
                    <h2>Cambiar contraseña</h2>
                </header>

                <form method="POST" action="{{ route('agent.profile.password') }}" class="form">
                    @csrf
                    @method('PUT')

                    <label for="current_password">Contraseña actual</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
                    @error('current_password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror

                    <label for="password">Nueva contraseña</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password">
                    @error('password')
                        <p class="field-error">{{ $message }}</p>
                    @enderror

                    <label for="password_confirmation">Confirmar nueva contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

                    <button type="submit" class="btn btn--primary">Actualizar contraseña</button>
                </form>
            </section>
        </div>
    </div>
@endsection

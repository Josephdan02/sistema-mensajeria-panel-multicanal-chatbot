@extends('agent.layouts.guest')

@section('content')
    @if ($alreadyAuthenticated)
        <div class="login__form">
            <p class="muted">Ya tienes una sesión de agente activa.</p>
            <a class="btn btn--primary btn--block" href="{{ route('agent.dashboard') }}">Ir al dashboard</a>
        </div>
    @else
        <form method="POST" action="{{ route('agent.login.store') }}" class="login__form">
            @csrf

            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
            @error('password')
                <p class="field-error">{{ $message }}</p>
            @enderror

            <label class="checkbox">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                Mantener sesión iniciada
            </label>

            <button type="submit" class="btn btn--primary btn--block">Iniciar sesión</button>
        </form>
    @endif
@endsection

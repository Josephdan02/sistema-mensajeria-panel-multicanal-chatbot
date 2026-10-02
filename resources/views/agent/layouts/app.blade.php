<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Centro de Agentes') · Cabanillas Dental</title>
    <link rel="stylesheet" href="{{ asset('css/agent.css') }}">
</head>
<body class="agent-app @yield('body-modifier')">
    <header class="topbar">
        <div class="topbar__brand">
            <span class="logo" aria-hidden="true">CD</span>
            <span class="topbar__brand-text">
                <strong>Cabanillas Dental</strong>
                <small>Centro de atención</small>
            </span>
        </div>

        <nav class="topnav" aria-label="Navegación principal">
            <a href="{{ route('agent.dashboard') }}" class="topnav__link @if (request()->routeIs('agent.dashboard')) is-active @endif">Dashboard</a>
            <a href="{{ route('agent.conversations.index') }}" class="topnav__link @if (request()->routeIs('agent.conversations.*')) is-active @endif">Conversaciones<span class="topnav__badge" data-nav-badge="waiting" hidden></span></a>
            <a href="{{ route('agent.contacts.index') }}" class="topnav__link @if (request()->routeIs('agent.contacts.*')) is-active @endif">Contactos</a>
            @can('manageUsers', App\Models\User::class)
                <a href="{{ route('agent.users.index') }}" class="topnav__link @if (request()->routeIs('agent.users.*')) is-active @endif">Usuarios</a>
            @endcan
            <a href="{{ route('agent.settings.index') }}" class="topnav__link @if (request()->routeIs('agent.settings.*')) is-active @endif">Ajustes</a>
            <a href="{{ route('agent.profile.show') }}" class="topnav__link @if (request()->routeIs('agent.profile.*')) is-active @endif">Mi perfil</a>
        </nav>

        <div class="topbar__alerts" data-agent-notifications data-notifications-url="{{ route('agent.notifications.summary') }}">
            <a class="bell" href="{{ route('agent.conversations.index', ['filter' => 'waiting']) }}" title="Conversaciones esperando y mensajes sin leer" aria-live="polite">
                <span class="bell__dot" aria-hidden="true"></span>
                <span aria-hidden="true">🔔</span>
                <span class="bell__count" data-notifications-count hidden>0</span>
                <span class="sr-only" data-notifications-label>Sin mensajes nuevos</span>
            </a>
        </div>

        <div class="topbar__user">
            <span class="avatar" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            <span class="topbar__userinfo">
                <strong>{{ auth()->user()->name }}</strong>
                <small>{{ auth()->user()->roleLabel() }}</small>
            </span>
            <form method="POST" action="{{ route('agent.logout') }}">
                @csrf
                <button type="submit" class="btn btn--ghost btn--sm">Salir</button>
            </form>
        </div>
    </header>

    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p class="flash flash--error" role="alert">
            {{ $errors->first() }}
        </p>
    @endif

    <main class="agent-main">
        @yield('content')
    </main>

    <script src="{{ asset('js/agent.js') }}" defer></script>
</body>
</html>

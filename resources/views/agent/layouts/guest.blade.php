<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso de agentes · Cabanillas Dental</title>
    <link rel="stylesheet" href="{{ asset('css/agent.css') }}">
</head>
<body class="agent-guest">
    <main class="login">
        <header class="login__brand">
            <span class="logo logo--lg" aria-hidden="true">CD</span>
            <h1>Centro de Agentes</h1>
            <p>Cabanillas Dental</p>
        </header>

        @if (session('status'))
            <p class="flash" role="status">{{ session('status') }}</p>
        @endif

        @yield('content')
    </main>

    <script src="{{ asset('js/agent.js') }}" defer></script>
</body>
</html>

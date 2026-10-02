<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asistente Virtual - Clínica Dental</title>
    <link rel="stylesheet" href="{{ asset('css/chatbot.css') }}">
</head>
<body>
    <main class="chat-app" aria-label="Asistente virtual de la Clínica Dental">
        <header class="chat-header">
            <div class="chat-header__avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5.5c-1.5-1.8-3.5-2.7-5.5-2.4C4 3.4 2.6 5.4 2.6 8c0 2.2.9 3.9 1.7 5.7.6 1.4 1 2.9 1.2 4.5.1 1 .5 2.3 1.6 2.3 1.4 0 1.7-1.7 1.9-3 .2-1.2.4-2.6 1.5-2.6h2.9c1.1 0 1.3 1.4 1.5 2.6.2 1.3.5 3 1.9 3 1.1 0 1.5-1.3 1.6-2.3.2-1.6.6-3.1 1.2-4.5.8-1.8 1.7-3.5 1.7-5.7 0-2.6-1.4-4.6-3.9-4.9-2-.3-4 .6-5.4 2.4z"/>
                </svg>
            </div>
            <div class="chat-header__title">
                <h1>Asistente Virtual</h1>
                <p>Clínica Dental</p>
            </div>
            <span id="chatbot-status" class="chat-header__status">En línea</span>
        </header>

        <section id="chatbot-messages" class="chat-messages" aria-live="polite" aria-label="Historial de mensajes"></section>

        <div id="chatbot-typing" class="chat-typing" hidden>
            <span class="chat-typing__dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>Escribiendo...</span>
        </div>

        <form id="chatbot-form" class="chat-form" autocomplete="off">
            <input
                id="chatbot-input"
                name="message"
                type="text"
                inputmode="text"
                maxlength="2000"
                placeholder="Escribe tu mensaje..."
                aria-label="Escribe tu mensaje"
                autocomplete="off"
            >
            <button id="chatbot-send" type="submit">Enviar</button>
        </form>

        <footer class="chat-footer">
            Demo del chatbot real: la lógica conversacional vive en el backend (ChatbotService → ApiAgendaService → Clínica).
        </footer>
    </main>

    <script src="{{ asset('js/chatbot.js') }}" defer></script>
</body>
</html>
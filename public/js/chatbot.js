/*
 * Interfaz del Asistente Virtual (Clínica Dental).
 *
 * Solo presenta y envía mensajes: TODA la lógica conversacional vive en el
 * backend de chatbot-clinica (POST /api/chat -> ChatController -> ChatbotService).
 *
 * Mecanismo de conversación (reutiliza el contrato existente del backend):
 *  - Se genera un user_identifier estable por pestaña ("web-<id>") y se guarda
 *    en sessionStorage.
 *  - El primer POST se envía sin conversation_id: el backend crea la
 *    Conversation (comportamiento actual de ChatController::message).
 *  - La respuesta trae data.conversation_id y se reutiliza en los siguientes
 *    envíos (el backend valida pertenencia por user_identifier).
 */
(function () {
    'use strict';

    var API_URL = '/api/chat';
    var STORAGE_KEY = 'chatbot.session.v1';

    var ui = {
        messages: document.getElementById('chatbot-messages'),
        form: document.getElementById('chatbot-form'),
        input: document.getElementById('chatbot-input'),
        send: document.getElementById('chatbot-send'),
        typing: document.getElementById('chatbot-typing'),
        status: document.getElementById('chatbot-status'),
    };

    var session = loadSession();

    function randomId() {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function loadSession() {
        var parsed = {};
        try {
            var raw = window.sessionStorage.getItem(STORAGE_KEY);
            parsed = raw ? JSON.parse(raw) : {};
        } catch (error) {
            parsed = {};
        }

        if (!parsed.userIdentifier) {
            parsed.userIdentifier = 'web-' + randomId();
        }

        parsed.conversationId = parsed.conversationId || null;
        // Cuando un agente humano toma la conversación el bot deja de responder.
        // Se mantiene solo en memoria para reflejarlo en la barra de estado.
        parsed.agentAttending = false;

        return parsed;
    }

    function saveSession() {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(session));
        } catch (error) {
            // sessionStorage puede no estar disponible (modo privado); la app
            // sigue funcionando, solo que cada recarga inicia conversación nueva.
        }
    }

    function scrollToEnd() {
        ui.messages.scrollTop = ui.messages.scrollHeight;
    }

    function addMessage(kind, text) {
        var bubble = document.createElement('div');
        bubble.className = 'chat-msg chat-msg--' + kind;
        bubble.textContent = text; // textContent evita inyección de HTML.
        ui.messages.appendChild(bubble);
        scrollToEnd();
    }

    function setBusy(busy) {
        ui.input.disabled = busy;
        ui.send.disabled = busy;
        ui.typing.hidden = !busy;
        ui.status.textContent = busy
            ? 'Escribiendo...'
            : (session.agentAttending ? 'Atendido por un agente' : 'En línea');

        if (busy) {
            scrollToEnd();
        } else {
            ui.input.focus();
        }
    }

    function friendlyError(status, body) {
        if (body && typeof body.message === 'string' && body.message) {
            return body.message; // El backend ya devuelve mensajes amigables.
        }

        if (status === 422) {
            return 'La solicitud contiene datos inválidos.';
        }

        return 'El asistente no está disponible en este momento. Inténtalo de nuevo en unos minutos.';
    }

    function sendMessage(text, isRetry) {
        var payload = {
            user_identifier: session.userIdentifier,
            message: text,
        };

        if (session.conversationId) {
            payload.conversation_id = session.conversationId;
        }

        setBusy(true);

        return window.fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                return { ok: response.ok, status: response.status, body: body };
            });
        }).then(function (result) {
            if (result.ok && result.body && result.body.success) {
                session.conversationId = result.body.data.conversation_id;
                session.agentAttending = result.body.data.handled_by === 'agent';
                saveSession();

                // Con un agente humano en control, el backend responde con
                // mensaje vacío (handled_by = "agent"): no se pinta burbuja.
                if (result.body.message) {
                    addMessage('bot', result.body.message);
                }

                return;
            }

            // La conversación guardada ya no existe en el backend (p. ej. base
            // de datos restaurada): se reintenta una única vez sin
            // conversation_id para que el backend cree una nueva.
            if (!isRetry && result.status === 404 && session.conversationId) {
                session.conversationId = null;
                saveSession();
                return sendMessage(text, true);
            }

            addMessage('system', friendlyError(result.status, result.body));
        }).catch(function () {
            addMessage('system', 'No pude conectar con el asistente. Verifica que el servidor esté disponible e inténtalo de nuevo.');
        }).then(function () {
            setBusy(false);
        });
    }

    ui.form.addEventListener('submit', function (event) {
        event.preventDefault();

        var text = ui.input.value.trim();

        if (!text || ui.send.disabled) {
            return;
        }

        ui.input.value = '';
        addMessage('user', text);
        sendMessage(text, false);
    });

    ui.input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();

            if (ui.form.requestSubmit) {
                ui.form.requestSubmit();
            } else {
                ui.form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    });

    // Saludo inicial: pasa realmente por POST /api/chat para que la respuesta
    // provenga de ChatbotService/ResponseGenerator (nada simulado en frontend).
    window.setTimeout(function () {
        sendMessage('hola', false);
    }, 250);
})();
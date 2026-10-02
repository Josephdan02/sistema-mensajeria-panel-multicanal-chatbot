/*
 * Centro de atención del Sistema de Agente (Cabanillas Dental).
 *
 * Fase 1: actualización en vivo por POLLING (sin WebSockets, sin Echo/Reverb).
 *  - Vista de conversación: refresca el hilo de mensajes y el listado lateral.
 *  - Bandeja: refresca el listado lateral.
 *  - Dashboard: refresca los contadores.
 *
 * Todo funciona también sin JavaScript: los formularios se envían por POST
 * normal y el polling es solo una mejora progresiva.
 */
(function () {
    'use strict';

    // Marca de disponibilidad de JS: el CSS activa con ella comportamientos
    // que solo tienen sentido cuando el JS esta presente.
    document.documentElement.classList.add('has-js');

    var THREAD_MS = 5000;
    var LIST_MS = 10000;
    var STATS_MS = 10000;
    var NOTIFS_MS = 15000;
    var COMPOSER_MAX_HEIGHT = 160;
    var POLLING_KEY = 'agent.pollingMs';
    var SOUND_KEY = 'agent.sound';

    function pollingMs(fallback) {
        try {
            var raw = window.localStorage.getItem(POLLING_KEY);
            var value = parseInt(raw, 10);

            if ([5000, 10000, 15000, 30000].indexOf(value) !== -1) {
                return value;
            }
        } catch (error) {
            // localStorage no disponible: se usa el valor por defecto.
        }

        return fallback;
    }

    function soundEnabled() {
        try {
            return window.localStorage.getItem(SOUND_KEY) === '1';
        } catch (error) {
            return false;
        }
    }

    function beep() {
        try {
            var AudioContext = window.AudioContext || window.webkitAudioContext;

            if (!AudioContext) {
                return;
            }

            var context = new AudioContext();
            var oscillator = context.createOscillator();
            var gain = context.createGain();

            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gain.gain.value = 0.08;
            oscillator.start();
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.4);
            oscillator.stop(context.currentTime + 0.4);
        } catch (error) {
            // Sin audio: la campana visual sigue funcionando.
        }
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function visible() {
        return document.hidden !== true;
    }

    function nearBottom(element) {
        return element.scrollHeight - element.scrollTop - element.clientHeight < 90;
    }

    function messageCount(element) {
        return element.querySelectorAll('.msg').length;
    }

    function fetchHtml(container, url) {
        return window.fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        }).then(function (html) {
            container.innerHTML = html;
        });
    }

    function pollList(container, url) {
        if (!container || !url) {
            return;
        }

        window.setInterval(function () {
            if (!visible()) {
                return;
            }

            fetchHtml(container, url).catch(function () {
                // Silencioso: se reintenta en el siguiente ciclo de polling.
            });
        }, pollingMs(LIST_MS));
    }

    /* ---------------------------------------------------- Vista conversación */

    var threadRoot = document.querySelector('[data-agent-thread]');
    var threadBox = document.getElementById('chat-thread');
    var listBox = document.getElementById('conv-list-wrapper');

    if (threadRoot && threadBox) {
        var threadUrl = threadRoot.getAttribute('data-thread-url');
        var listUrl = threadRoot.getAttribute('data-sidebar-url');
        var jumpButton = document.getElementById('chat-jump');
        var jumpCount = jumpButton ? jumpButton.querySelector('[data-chat-jump-count]') : null;
        var pendingNew = 0;

        var scrollToBottom = function (smooth) {
            var top = threadBox.scrollHeight;

            if (smooth && typeof threadBox.scrollTo === 'function') {
                threadBox.scrollTo({ top: top, behavior: 'smooth' });
            } else {
                threadBox.scrollTop = top;
            }
        };

        var updateJump = function () {
            if (!jumpButton) {
                return;
            }

            var hasPending = pendingNew > 0;

            if (jumpCount) {
                jumpCount.textContent = hasPending ? String(pendingNew) : '';
                jumpCount.hidden = !hasPending;
            }

            jumpButton.hidden = !hasPending;
        };

        var clearPending = function () {
            if (pendingNew === 0 && (!jumpButton || jumpButton.hidden)) {
                return;
            }

            pendingNew = 0;
            updateJump();
        };

        var refreshThread = function (force) {
            if (!visible() && !force) {
                return Promise.resolve();
            }

            var stick = nearBottom(threadBox);
            var previousTop = threadBox.scrollTop;
            var previousCount = messageCount(threadBox);

            return fetchHtml(threadBox, threadUrl).then(function () {
                var count = messageCount(threadBox);

                // El agente esta al final (o el refresco es forzado): seguir el hilo.
                if (stick || force) {
                    clearPending();
                    scrollToBottom(false);
                    return;
                }

                // El agente esta leyendo mensajes antiguos: no mover su posicion.
                threadBox.scrollTop = previousTop;

                if (count > previousCount) {
                    pendingNew += count - previousCount;
                    updateJump();
                }
            }).catch(function () {
                // Silencioso: el siguiente ciclo reintenta.
            });
        };

        // Al abrir la conversacion se muestra siempre lo mas reciente.
        scrollToBottom(false);
        refreshThread(true);
        window.setInterval(function () { refreshThread(false); }, pollingMs(THREAD_MS));
        pollList(listBox, listUrl);

        threadBox.addEventListener('scroll', function () {
            if (nearBottom(threadBox)) {
                clearPending();
            }
        }, { passive: true });

        if (jumpButton) {
            jumpButton.addEventListener('click', function () {
                clearPending();
                scrollToBottom(true);
            });
        }

        // Composer: envio por fetch (con degradacion al POST normal del formulario).
        var form = document.querySelector('[data-reply-form]');
        var composer = form ? form.querySelector('[data-reply-input]') : null;
        var sendButton = form ? form.querySelector('[data-reply-send]') : null;
        var hint = form ? form.querySelector('[data-reply-hint]') : null;
        var sendLabel = sendButton ? sendButton.textContent : '';

        var autoGrow = function () {
            if (!composer) {
                return;
            }

            composer.style.height = 'auto';
            composer.style.height = Math.min(composer.scrollHeight, COMPOSER_MAX_HEIGHT) + 'px';
        };

        if (composer) {
            composer.addEventListener('input', autoGrow);

            // Enter envia; Shift+Enter (o Ctrl/Cmd/Alt+Enter) inserta una linea.
            composer.addEventListener('keydown', function (event) {
                var withModifier = event.shiftKey || event.ctrlKey || event.altKey || event.metaKey;

                if (event.key !== 'Enter' || withModifier || event.isComposing || !form) {
                    return;
                }

                event.preventDefault();

                if (composer.value.trim() === '') {
                    return;
                }

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }

        if (hint) {
            hint.hidden = false;
        }

        if (form && typeof window.fetch === 'function') {
            var errorBox = form.querySelector('[data-reply-error]');

            form.addEventListener('submit', function (event) {
                var textarea = form.querySelector('textarea[name="message"]');
                var text = textarea ? textarea.value.trim() : '';

                if (!text || form.getAttribute('data-sending') === '1') {
                    event.preventDefault();
                    return;
                }

                event.preventDefault();
                form.setAttribute('data-sending', '1');

                if (sendButton) {
                    sendButton.disabled = true;
                    sendButton.textContent = 'Enviando...';
                }

                if (errorBox) {
                    errorBox.hidden = true;
                }

                var body = new FormData();
                body.append('message', text);

                window.fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: body,
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (payload) {
                        return { ok: response.ok, body: payload };
                    });
                }).then(function (result) {
                    if (!result.ok || !result.body.success) {
                        throw new Error(result.body.message || 'No se pudo enviar el mensaje.');
                    }

                    if (textarea) {
                        textarea.value = '';
                    }

                    return refreshThread(true);
                }).catch(function (error) {
                    if (errorBox) {
                        errorBox.textContent = error.message || 'No se pudo enviar el mensaje.';
                        errorBox.hidden = false;
                    } else {
                        window.alert('No se pudo enviar el mensaje: ' + (error.message || ''));
                    }
                }).then(function () {
                    form.setAttribute('data-sending', '0');

                    if (sendButton) {
                        sendButton.disabled = false;
                        sendButton.textContent = sendLabel;
                    }

                    if (composer) {
                        autoGrow();
                        composer.focus();
                    }
                });
            });
        }
    }

    /* ------------------------------------------------------------ Bandeja */

    var inboxRoot = document.querySelector('[data-agent-inbox]');

    if (inboxRoot) {
        pollList(listBox, inboxRoot.getAttribute('data-sidebar-url'));
    }

    /* ---------------------------------------------------------- Dashboard */

    var statsRoot = document.querySelector('[data-agent-stats]');

    if (statsRoot) {
        var statsUrl = statsRoot.getAttribute('data-stats-url');

        window.setInterval(function () {
            if (!visible() || !statsUrl) {
                return;
            }

            window.fetch(statsUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (payload) {
                if (!payload || !payload.data) {
                    return;
                }

                Object.keys(payload.data).forEach(function (key) {
                    var target = statsRoot.querySelector('[data-stat="' + key + '"]');

                    if (target) {
                        target.textContent = payload.data[key];
                    }
                });
            }).catch(function () {
                // Silencioso: se reintenta en el siguiente ciclo.
            });
        }, pollingMs(STATS_MS));
    }

    /* ------------------------- Campana de notificaciones (polling ligero) */

    var notifRoot = document.querySelector('[data-agent-notifications]');

    if (notifRoot && typeof window.fetch === 'function') {
        var notifUrl = notifRoot.getAttribute('data-notifications-url');
        var notifBell = notifRoot.querySelector('.bell');
        var notifCount = notifRoot.querySelector('[data-notifications-count]');
        var notifLabel = notifRoot.querySelector('[data-notifications-label]');
        var navWaiting = document.querySelector('[data-nav-badge="waiting"]');
        var lastUnread = 0;

        var renderNotifs = function (data) {
            var waiting = Number(data.waiting || 0);
            var unread = Number(data.unread || 0);
            var unreadWaiting = Number(data.unread_waiting || 0);
            var total = unread + waiting;

            if (notifBell) {
                notifBell.classList.toggle('has-unread', total > 0);
                notifBell.setAttribute(
                    'title',
                    total > 0
                        ? waiting + ' esperando · ' + unread + ' sin leer (' + unreadWaiting + ' en cola)'
                        : 'Sin mensajes nuevos'
                );
            }

            if (notifCount) {
                notifCount.textContent = String(total);
                notifCount.hidden = total === 0;
            }

            if (notifLabel) {
                notifLabel.textContent = total > 0
                    ? waiting + ' en espera, ' + unread + ' mensajes sin leer'
                    : 'Sin mensajes nuevos';
            }

            if (navWaiting) {
                navWaiting.textContent = waiting > 0 ? String(waiting) : '';
                navWaiting.hidden = waiting === 0;
            }

            if (soundEnabled() && visible() && unread > lastUnread && lastUnread !== 0) {
                beep();
            }

            lastUnread = unread;
        };

        var refreshNotifs = function () {
            if (!visible() || !notifUrl) {
                return;
            }

            window.fetch(notifUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (payload) {
                if (payload && payload.success && payload.data) {
                    renderNotifs(payload.data);
                }
            }).catch(function () {
                // Silencioso: se reintenta en el siguiente ciclo.
            });
        };

        refreshNotifs();
        window.setInterval(refreshNotifs, pollingMs(NOTIFS_MS));
    }

    /* --------------------------------------- Ajustes: preferencias locales */

    var settingsRoot = document.querySelector('[data-agent-settings]');

    if (settingsRoot) {
        var pollingSelect = settingsRoot.querySelector('[data-settings-polling]');
        var soundCheck = settingsRoot.querySelector('[data-settings-sound]');
        var settingsForm = settingsRoot.querySelector('[data-settings-form]');
        var resetButton = settingsRoot.querySelector('[data-settings-reset]');

        try {
            if (pollingSelect) {
                pollingSelect.value = String(pollingMs(5000));
            }

            if (soundCheck) {
                soundCheck.checked = soundEnabled();
            }
        } catch (error) {
            // Valores por defecto del HTML.
        }

        if (settingsForm) {
            settingsForm.addEventListener('submit', function (event) {
                event.preventDefault();

                try {
                    if (pollingSelect) {
                        window.localStorage.setItem(POLLING_KEY, pollingSelect.value);
                    }

                    if (soundCheck) {
                        window.localStorage.setItem(SOUND_KEY, soundCheck.checked ? '1' : '0');
                    }
                } catch (error) {
                    window.alert('No se pudo guardar en este navegador.');
                    return;
                }

                window.alert('Preferencias guardadas en este navegador. Se aplican al recargar.');
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function () {
                try {
                    window.localStorage.removeItem(POLLING_KEY);
                    window.localStorage.removeItem(SOUND_KEY);
                } catch (error) {
                    // Sin almacenamiento: solo se restablece el formulario.
                }

                if (pollingSelect) {
                    pollingSelect.value = '5000';
                }

                if (soundCheck) {
                    soundCheck.checked = false;
                }
            });
        }
    }
})();

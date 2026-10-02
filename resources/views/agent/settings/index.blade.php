@extends('agent.layouts.app')

@section('title', 'Ajustes')

@section('content')
    <div class="page page--narrow">
        <header class="page__head">
            <h1>Ajustes del centro de atención</h1>
            <p class="muted">
                Preferencias de este navegador: se guardan localmente (localStorage) y no requieren servidor.
            </p>
        </header>

        <section class="panel" data-agent-settings>
            <header class="panel__head">
                <h2>Actualización en vivo (polling)</h2>
            </header>

            <form class="form" data-settings-form>
                <label for="polling">Intervalo de actualización</label>
                <select id="polling" data-settings-polling>
                    <option value="5000">Cada 5 segundos (recomendado)</option>
                    <option value="10000">Cada 10 segundos</option>
                    <option value="15000">Cada 15 segundos</option>
                    <option value="30000">Cada 30 segundos</option>
                </select>
                <p class="muted muted--sm">Aplica al hilo de mensajes, la bandeja lateral y los contadores.</p>

                <label class="check">
                    <input type="checkbox" data-settings-sound>
                    <span>Avisar con sonido cuando lleguen mensajes sin leer</span>
                </label>

                <div class="form__actions">
                    <button type="submit" class="btn btn--primary btn--sm">Guardar en este navegador</button>
                    <button type="button" class="btn btn--sm" data-settings-reset>Restablecer</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <header class="panel__head">
                <h2>Estado actual</h2>
            </header>
            <dl class="facts">
                <dt>Esperando agente</dt>
                <dd>{{ $stats['waiting'] ?? 0 }}</dd>
                <dt>Mis conversaciones</dt>
                <dd>{{ $stats['mine'] ?? 0 }}</dd>
                <dt>Mensajes sin leer</dt>
                <dd>{{ $stats['unread'] ?? 0 }}</dd>
            </dl>
            <p class="muted muted--sm">
                Pendiente documentado (no inventado): notificaciones push, recordatorios por email y horarios de
                atención requieren infraestructura que hoy no existe (colas/cron/WebSockets). Quedan para la
                siguiente fase.
            </p>
        </section>
    </div>
@endsection

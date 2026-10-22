{{--
    Panel del contacto/paciente.
    Requiere: $conversation (App\Models\Conversation) y $clinic (salida de App\Services\Agent\ClinicInfoService).
    Los datos clínicos NO se copian localmente: se consultan en vivo a CABANILLAS vía API.
--}}
@php
    $agents = $agents ?? collect();
    $patient = $clinic['patient'] ?? null;
    $patientName = \App\Services\Agent\ClinicInfoService::patientFullName($patient);
    // Teléfono/correo se toleran en ambos formatos porque CABANILLAS puede
    // nombrar las claves en español o inglés según el endpoint.
    $phone = $patient['telefono'] ?? $patient['phone'] ?? null;
    $email = $patient['email'] ?? $patient['correo'] ?? null;
    $document = $patient['numero_documento'] ?? null;
    $slots = $conversation->getContextValue('slots', []);
    $declaredService = is_array($slots) ? ($slots['service'] ?? null) : null;
    $declaredDate = is_array($slots) ? ($slots['date'] ?? null) : null;
    $declaredTime = is_array($slots) ? ($slots['time'] ?? null) : null;
    $uncertainCreation = is_array($slots) && ($slots['appointment_creation_status'] ?? null) === 'unknown';
    $uncertainOperation = is_array($slots) && ($slots['appointment_operation_status'] ?? null) === 'unknown';
    $uncertainOperationLabel = match (is_array($slots) ? ($slots['appointment_operation'] ?? null) : null) {
        'confirm' => 'confirmar',
        'cancel' => 'cancelar',
        'reschedule' => 'reprogramar',
        default => 'gestionar',
    };
    $identityLabel = match ($clinic['patient_status'] ?? 'not_found') {
        'verified' => 'Identificado en CABANILLAS',
        'ambiguous' => 'Identidad ambigua',
        'technical_error' => 'No se pudo verificar',
        'invalid_data' => 'Datos no verificables',
        default => $clinic['has_identifiers'] ? 'Paciente no encontrado' : 'No identificado',
    };
@endphp

<section class="contact-panel" aria-label="Datos del contacto">
    <h2>Contacto</h2>

    <dl class="facts">
        <dt>Identificador</dt>
        <dd>{{ $conversation->displayName() }}</dd>
        <dt>Canal</dt>
        <dd>{{ $conversation->channelLabel() }}</dd>
        <dt>Identificación</dt>
        <dd><span class="status-note status-note--{{ ($clinic['patient_status'] ?? '') === 'verified' ? 'ok' : 'pending' }}">{{ $identityLabel }}</span></dd>
        <dt>Servicio solicitado</dt>
        <dd>{{ $declaredService ?: 'No indicado' }}</dd>
        <dt>Preferencia</dt>
        <dd>
            @if ($declaredDate || $declaredTime)
                {{ $declaredDate ?: 'Fecha no indicada' }}{{ $declaredTime ? ' · '.$declaredTime : '' }}
            @else
                No indicada
            @endif
        </dd>
        <dt>Estado</dt>
        <dd>@include('agent.partials.state-badge', ['state' => $conversation->state])</dd>
        <dt>Agente</dt>
        <dd>{{ $conversation->assignedAgent?->name ?? 'Sin asignar' }}</dd>
        <dt>Asignada</dt>
        <dd>{{ $conversation->assigned_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        <dt>Último mensaje</dt>
        <dd>{{ $conversation->last_message_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        <dt>Cerrada</dt>
        <dd>{{ $conversation->closed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
        <dt>Cerrada por</dt>
        <dd>{{ $conversation->closedByUser?->name ?? '—' }}</dd>
    </dl>

    @if ($uncertainCreation || $uncertainOperation)
        <p class="alert alert--warning">
            No se pudo confirmar el resultado de
            @if ($uncertainCreation && $uncertainOperation)
                la creación y la operación de la cita
            @elseif ($uncertainCreation)
                la creación de la cita
            @else
                la operación para {{ $uncertainOperationLabel }} la cita
            @endif.
            Verifica el estado directamente en CABANILLAS antes de repetirla.
        </p>
    @endif

    <h3>Datos del paciente</h3>

    @if (! $clinic['has_identifiers'])
        <p class="muted">El chatbot todavía no identificó al paciente; no hay datos suficientes para verificarlo en CABANILLAS.</p>
    @elseif (($clinic['patient_status'] ?? '') === 'ambiguous')
        <p class="alert alert--warning">
            No se muestran datos clínicos porque el documento coincide con varios pacientes en CABANILLAS.
            Un agente debe verificar la identidad antes de continuar.
        </p>
    @else
        <p class="muted muted--sm">
            Los datos declarados por el paciente no se consideran verificados hasta coincidir con CABANILLAS.
        </p>
        @if (! $clinic['ok'])
            <p class="alert alert--warning">
                No se pudo consultar la API clínica: {{ $clinic['error'] }}
                Se muestran únicamente los datos locales de la conversación.
            </p>
        @endif

        @if (($clinic['patient_status'] ?? '') !== 'verified')
            <p class="alert alert--warning">
                {{ $clinic['error'] ?? 'La información del paciente no pudo verificarse en CABANILLAS.' }}
                Solo se muestran datos declarados en la conversación.
            </p>
        @endif

        @if (($clinic['patient_status'] ?? '') === 'verified')
        <dl class="facts">
            <dt>Nombre completo</dt>
            <dd>{{ $patientName ?? '—' }}</dd>
            <dt>Documento</dt>
            <dd>
                {{ $document ?? '—' }}
                @if (($patient['tipo_documento'] ?? '') !== '')
                    <span class="table__cell-note">{{ $patient['tipo_documento'] }}</span>
                @endif
            </dd>
            <dt>Teléfono</dt>
            <dd>{{ $phone ?? '—' }}</dd>
            <dt>Correo</dt>
            <dd>{{ $email ?? '—' }}</dd>
            <dt>paciente_id</dt>
            <dd>{{ $clinic['identifiers']['patient_id'] ?? '—' }}</dd>
            <dt>Cita en contexto</dt>
            <dd>{{ $clinic['identifiers']['appointment_id'] ?? '—' }}</dd>
        </dl>
        @else
            <dl class="facts">
                <dt>Documento declarado</dt>
                <dd>{{ $clinic['declared']['document'] ?? '—' }}</dd>
                <dt>Servicio declarado</dt>
                <dd>{{ $clinic['declared']['service'] ?? '—' }}</dd>
                <dt>Preferencia declarada</dt>
                <dd>{{ $clinic['declared']['date'] ?? '—' }}{{ ! empty($clinic['declared']['time']) ? ' · '.$clinic['declared']['time'] : '' }}</dd>
            </dl>
        @endif

        @if ($clinic['patient_status'] === 'verified' && $clinic['ok'] && $patient !== null && $phone === null && $email === null)
            <p class="muted muted--sm">CABANILLAS no devolvió teléfono ni correo para este paciente.</p>
        @endif

        @if ($clinic['ok'])
            @if ($clinic['appointment'] !== null)
                <h4>Cita tratada en el chatbot</h4>
                <ul class="appt-list">
                    @include('agent.partials.appointment', ['appointment' => $clinic['appointment'], 'highlight' => true])
                </ul>
            @endif

            <h4>Próximas citas</h4>
            @if (! empty($clinic['upcoming']))
                <ul class="appt-list">
                    @foreach (array_slice($clinic['upcoming'], 0, 3) as $appointment)
                        @include('agent.partials.appointment', ['appointment' => $appointment])
                    @endforeach
                </ul>
            @else
                <p class="muted muted--sm">Sin citas próximas registradas en CABANILLAS.</p>
            @endif

            <h4>Últimas citas</h4>
            @if (! empty($clinic['past']))
                <ul class="appt-list">
                    @foreach (array_slice($clinic['past'], 0, 5) as $appointment)
                        @include('agent.partials.appointment', ['appointment' => $appointment])
                    @endforeach
                </ul>
            @else
                <p class="muted muted--sm">Sin citas anteriores registradas en CABANILLAS.</p>
            @endif
        @endif
    @endif

    <section class="contact-actions" aria-label="Acciones de la conversación">
        <h3>Acciones</h3>

        <form class="stack-form" method="POST" action="{{ route('agent.conversations.assign', $conversation) }}">
            @csrf
            <input type="hidden" name="filter" value="{{ $filter }}">
            <label for="agent_id">Asignar a</label>
            <select id="agent_id" name="agent_id">
                @foreach ($agents as $agent)
                    <option value="{{ $agent->id }}" @selected($conversation->assigned_agent_id === $agent->id)>
                        {{ $agent->name }} ({{ $agent->roleLabel() }})
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn--sm btn--block">Asignar</button>
        </form>

        <form class="stack-form" method="POST" action="{{ route('agent.conversations.state', $conversation) }}">
            @csrf
            <input type="hidden" name="filter" value="{{ $filter }}">
            <label for="state">Cambiar estado</label>
            <select id="state" name="state">
                @foreach (\App\Enums\ConversationState::selectable() as $selectable)
                    <option value="{{ $selectable->value }}" @selected($conversation->state === $selectable)>
                        {{ $selectable->label() }}
                    </option>
                @endforeach
            </select>
            <input type="text" name="note" maxlength="500" placeholder="Nota (opcional)">
            <button type="submit" class="btn btn--sm btn--block">Actualizar estado</button>
        </form>
    </section>
</section>

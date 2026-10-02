{{--
    Item de cita con datos de CABANILLAS.
    Requiere $appointment (cita ya normalizada por ClinicInfoService/ApiAgendaService).
    Opcional: $highlight (destaca la cita tratada en el chatbot).
--}}
@php
    $highlight = $highlight ?? false;
    $status = is_scalar($appointment['status'] ?? null) ? (string) $appointment['status'] : '';
    $statusModifier = $status !== '' ? str_replace('_', '-', $status) : 'unknown';
    $statusLabel = $appointment['status_label']
        ?? \App\Services\Agent\ClinicInfoService::appointmentStatusLabel($status !== '' ? $status : null);
@endphp

<li class="appt @if ($highlight) appt--highlight @endif">
    <span class="appt__head">
        <span class="appt__when">
            #{{ $appointment['id'] ?? '—' }} ·
            {{ $appointment['date'] ?? '—' }} {{ $appointment['time'] ?? '' }}
        </span>
        <span class="badge badge--{{ $statusModifier }}">{{ $statusLabel }}</span>
    </span>
    <span class="appt__meta">{{ $appointment['service'] ?? 'Servicio no informado' }}</span>
    <span class="appt__meta">{{ $appointment['professional'] ?? 'Profesional no informado' }}</span>
</li>

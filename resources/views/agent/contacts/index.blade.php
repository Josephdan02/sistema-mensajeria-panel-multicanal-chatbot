@extends('agent.layouts.app')

@section('title', 'Contactos')

@section('content')
    <div class="page">
        <header class="page__head">
            <h1>Contactos</h1>
            <p class="muted">
                Los contactos se derivan de las conversaciones del chatbot: no existe una tabla local de pacientes.
                El nombre y el DNI se consultan en vivo a CABANILLAS; si la API no responde se muestran solo los datos locales.
            </p>

            <form method="GET" action="{{ route('agent.contacts.index') }}" class="search">
                <input type="search" name="search" value="{{ $search }}" placeholder="Buscar por identificador, canal o DNI guardado en la conversación..." aria-label="Buscar contacto">
                <button type="submit" class="btn btn--sm">Buscar</button>
            </form>

            <p class="muted muted--sm">
                La búsqueda usa los datos guardados en las conversaciones (identificador, canal y DNI capturado por el
                chatbot). El nombre del paciente solo aparece tras consultar CABANILLAS en cada fila, por lo que no se
                puede buscar por nombre sin consultar la API contacto por contacto.
            </p>

            @if ($search !== '')
                <p class="muted muted--sm">
                    {{ $contacts->total() }} resultado(s) para «{{ $search }}».
                    <a class="link" href="{{ route('agent.contacts.index') }}">Limpiar búsqueda</a>
                </p>
            @endif
        </header>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Contacto</th>
                        <th>Paciente (CABANILLAS)</th>
                        <th>DNI</th>
                        <th>Conversaciones</th>
                        <th>Última conversación</th>
                        <th>Agente</th>
                        <th>Última actividad</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($contacts as $contact)
                        @php
                            $conversation = $contact['conversation'];
                            $clinic = $contact['clinic'];
                            $patient = $clinic['patient'] ?? null;
                            $patientName = \App\Services\Agent\ClinicInfoService::patientFullName($patient);
                            $document = $patient['numero_documento'] ?? $contact['local_document'];
                        @endphp
                        <tr>
                            <td>
                                <span class="table__cell-title">{{ $contact['identifier'] }}</span>
                                <span class="table__cell-note">{{ $contact['channel'] === 'whatsapp' ? 'WhatsApp' : 'Web' }}</span>
                            </td>

                            <td>
                                @if ($patientName !== null)
                                    {{ $patientName }}
                                @elseif ($clinic !== null && ! $clinic['ok'])
                                    <span class="table__cell-note table__cell-note--warn"
                                          title="{{ $clinic['error'] }}">Datos clínicos no consultados</span>
                                @elseif ($contact['local_document'] === null)
                                    <span class="table__cell-note">El chatbot aún no capturó el DNI</span>
                                @else
                                    <span class="table__cell-note">Documento sin paciente en CABANILLAS</span>
                                @endif
                            </td>

                            <td>{{ $document ?? '—' }}</td>

                            <td>
                                {{ $contact['conversations_count'] }}
                                @if ($contact['waiting_count'] > 0)
                                    <span class="chip chip--warn">{{ $contact['waiting_count'] }} esperando</span>
                                @endif
                            </td>

                            <td>
                                @if ($conversation !== null)
                                    <a class="link" href="{{ route('agent.conversations.show', ['conversation' => $conversation->id]) }}">
                                        Abrir #{{ $conversation->id }}
                                    </a>
                                    <span class="table__cell-note">
                                        @include('agent.partials.state-badge', ['state' => $conversation->state])
                                        @if (($conversation->unread_messages_count ?? 0) > 0)
                                            <span class="unread" title="Mensajes sin leer">{{ $conversation->unread_messages_count }}</span>
                                        @endif
                                    </span>
                                @else
                                    —
                                @endif
                            </td>

                            <td>{{ $conversation?->assignedAgent?->name ?? 'Sin asignar' }}</td>

                            <td>{{ $contact['last_activity_at']?->format('d/m/Y H:i') ?? '—' }}</td>

                            <td>
                                @if ($conversation !== null)
                                    <a class="link" href="{{ route('agent.conversations.show', ['conversation' => $conversation->id]) }}">
                                        Ver conversación
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty">No hay contactos registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('agent.partials.pagination', ['paginator' => $contacts])
    </div>
@endsection

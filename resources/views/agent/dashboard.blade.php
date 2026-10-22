@extends('agent.layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page" data-agent-stats data-stats-url="{{ route('agent.dashboard.stats') }}">
        <header class="page__head">
            <h1>Dashboard</h1>
            <p class="muted">Resumen del centro de atención. Los contadores se actualizan en vivo.</p>
        </header>

        @php
            $cards = [
                ['key' => 'waiting', 'label' => 'Pendientes de atención', 'tone' => 'warn', 'filter' => 'waiting'],
                ['key' => 'mine', 'label' => 'Mis conversaciones', 'tone' => 'info', 'filter' => 'mine'],
                ['key' => 'assigned', 'label' => 'Asignadas a un agente', 'tone' => 'info', 'filter' => 'assigned'],
                ['key' => 'attending', 'label' => 'En atención', 'tone' => 'ok', 'filter' => 'attending'],
                ['key' => 'closed', 'label' => 'Cerradas', 'tone' => 'muted', 'filter' => 'closed'],
                ['key' => 'bot', 'label' => 'Atendidas automáticamente', 'tone' => 'muted', 'filter' => 'bot'],
                ['key' => 'unread', 'label' => 'Mensajes sin leer', 'tone' => 'danger', 'filter' => 'waiting'],
            ];
        @endphp

        <section class="stat-grid" aria-label="Contadores de conversaciones">
            @foreach ($cards as $card)
                <a class="stat stat--{{ $card['tone'] }}"
                   href="{{ route('agent.conversations.index', ['filter' => $card['filter']]) }}">
                    <span class="stat__value" data-stat="{{ $card['key'] }}">{{ $stats[$card['key']] ?? 0 }}</span>
                    <span class="stat__label">{{ $card['label'] }}</span>
                </a>
            @endforeach
        </section>

        <div class="two-col">
            <section class="panel">
                <header class="panel__head">
                    <h2>Pendientes de atención</h2>
                    <a class="link" href="{{ route('agent.conversations.index', ['filter' => 'waiting']) }}">Ver todas</a>
                </header>

                @forelse ($waiting as $conversation)
                    <a class="row" href="{{ route('agent.conversations.show', $conversation) }}">
                        <span class="row__title">{{ $conversation->displayName() }}</span>
                        <span class="row__meta">
                            @include('agent.partials.state-badge', ['state' => $conversation->state])
                            <time>{{ ($conversation->last_message_at ?? $conversation->updated_at)->format('d/m H:i') }}</time>
                        </span>
                    </a>
                @empty
                    <p class="empty">No hay conversaciones pendientes.</p>
                @endforelse

                @if ($stats['unread_waiting'] > 0)
                    <p class="panel__note">{{ $stats['unread_waiting'] }} mensaje(s) sin leer en la cola de espera.</p>
                @endif
            </section>

            <section class="panel">
                <header class="panel__head">
                    <h2>Mis conversaciones</h2>
                    <a class="link" href="{{ route('agent.conversations.index', ['filter' => 'mine']) }}">Ver todas</a>
                </header>

                @forelse ($mine as $conversation)
                    <a class="row" href="{{ route('agent.conversations.show', $conversation) }}">
                        <span class="row__title">{{ $conversation->displayName() }}</span>
                        <span class="row__meta">
                            @include('agent.partials.state-badge', ['state' => $conversation->state])
                            <time>{{ ($conversation->last_message_at ?? $conversation->updated_at)->format('d/m H:i') }}</time>
                        </span>
                    </a>
                @empty
                    <p class="empty">Todavía no tienes conversaciones asignadas.</p>
                @endforelse
            </section>
        </div>
    </div>
@endsection

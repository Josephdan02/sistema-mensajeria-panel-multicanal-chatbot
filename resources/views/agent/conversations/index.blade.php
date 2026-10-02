@extends('agent.layouts.app')

@section('title', 'Conversaciones')

@section('content')
    <div class="workspace workspace--inbox"
         data-agent-inbox
         data-sidebar-url="{{ route('agent.conversations.sidebar', ['filter' => $filter, 'search' => $search]) }}">
        <aside class="workspace__sidebar">
            <div class="sidebar-head">
                <form method="GET" action="{{ route('agent.conversations.index') }}" class="search">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <input type="search" name="search" value="{{ $search }}" placeholder="Buscar por identificador..." aria-label="Buscar conversación">
                    <button type="submit" class="btn btn--sm">Buscar</button>
                </form>

                <div class="filter-chips" role="tablist">
                    @foreach ($filters as $key => $label)
                        <a class="chip-link @if ($filter === $key) is-active @endif"
                           href="{{ route('agent.conversations.index', ['filter' => $key, 'search' => $search]) }}">{{ $label }}</a>
                    @endforeach
                </div>

                <p class="muted muted--sm">
                    {{ $stats['waiting'] }} esperando · {{ $stats['attending'] }} en atención · {{ $stats['unread'] }} mensajes sin leer
                </p>
            </div>

            <section class="sidebar-body" id="conv-list-wrapper" aria-label="Bandeja de conversaciones">
                @include('agent.conversations._list', [
                    'conversations' => $conversations,
                    'filter' => $filter,
                    'activeId' => null,
                ])
            </section>
        </aside>

        <section class="workspace__placeholder">
            <div class="placeholder">
                <h2>Centro de atención</h2>
                <p class="muted">Selecciona una conversación para ver el historial y responder al cliente.</p>
                <p class="muted">
                    Las conversaciones atendidas por el bot no envían respuestas automáticas mientras un agente
                    tiene el control: el handoff es silencioso y el cliente solo recibe lo que escriba el agente.
                </p>
            </div>
        </section>
    </div>
@endsection

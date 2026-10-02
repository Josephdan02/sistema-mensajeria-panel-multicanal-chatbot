@extends('agent.layouts.app')

@section('title', 'Conversación #'.$conversation->id)
@section('body-modifier', 'agent-app--shell')

@section('content')
    <div class="workspace workspace--thread"
         data-agent-thread
         data-thread-url="{{ route('agent.conversations.thread', $conversation) }}"
         data-sidebar-url="{{ route('agent.conversations.sidebar', ['filter' => $filter, 'active' => $conversation->id]) }}"
         data-conversation-id="{{ $conversation->id }}">

        <aside class="workspace__sidebar">
            <div class="sidebar-head">
                <a class="btn btn--ghost btn--sm" href="{{ route('agent.conversations.index', ['filter' => $filter]) }}">← Volver a la bandeja</a>
                <div class="filter-chips">
                    @foreach ($filters as $key => $label)
                        <a class="chip-link @if ($filter === $key) is-active @endif"
                           href="{{ route('agent.conversations.index', ['filter' => $key]) }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>

            <section class="sidebar-body" id="conv-list-wrapper" aria-label="Bandeja de conversaciones">
                @include('agent.conversations._list', [
                    'conversations' => $conversations,
                    'filter' => $filter,
                    'activeId' => $conversation->id,
                ])
            </section>
        </aside>

        <section class="workspace__main">
            <header class="chat-head">
                <span class="chat-head__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($conversation->displayName(), 0, 2)) }}</span>

                <div class="chat-head__info">
                    <h1>{{ $conversation->displayName() }}</h1>
                    <p class="chat-head__meta">
                        @include('agent.partials.state-badge', ['state' => $conversation->state])
                        <span class="chip">{{ $conversation->channelLabel() }}</span>
                        @if ($conversation->assignedAgent !== null)
                            <span class="chip">Atiende: {{ $conversation->assignedAgent->name }}</span>
                        @else
                            <span class="chip chip--warn">Sin agente asignado</span>
                        @endif
                        @if ($unreadBefore > 0)
                            <span class="unread" title="Mensajes sin leer al abrir">{{ $unreadBefore }}</span>
                        @endif
                    </p>
                </div>

                <div class="chat-head__actions">
                    <form method="POST" action="{{ route('agent.conversations.take', $conversation) }}">
                        @csrf
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <button type="submit" class="btn btn--primary btn--sm">Tomar</button>
                    </form>

                    <form method="POST" action="{{ route('agent.conversations.queue', $conversation) }}">
                        @csrf
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <button type="submit" class="btn btn--sm">Devolver a la cola</button>
                    </form>

                    <form method="POST" action="{{ route('agent.conversations.release', $conversation) }}">
                        @csrf
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <button type="submit" class="btn btn--sm">Devolver al bot</button>
                    </form>

                    <form method="POST" action="{{ route('agent.conversations.close', $conversation) }}">
                        @csrf
                        <input type="hidden" name="filter" value="{{ $filter }}">
                        <button type="submit" class="btn btn--danger btn--sm">Cerrar</button>
                    </form>
                </div>
            </header>

            <div class="chat-thread-wrap">
                <div class="chat-thread" id="chat-thread" aria-live="polite" aria-label="Historial de mensajes">
                    @include('agent.conversations._thread', ['conversation' => $conversation, 'messages' => $messages])
                </div>

                <button type="button" class="chat-jump" id="chat-jump" data-chat-jump hidden>
                    <span aria-hidden="true">&#8595;</span>
                    <span>Nuevos mensajes</span>
                    <span class="chat-jump__count" data-chat-jump-count hidden></span>
                </button>
            </div>

            <form class="chat-reply" method="POST" action="{{ route('agent.conversations.reply', $conversation) }}" data-reply-form>
                @csrf
                <input type="hidden" name="filter" value="{{ $filter }}">
                <div class="chat-reply__field">
                    <textarea name="message" rows="1" maxlength="2000" required
                              placeholder="Escribe la respuesta al cliente..." aria-label="Respuesta al cliente"
                              data-reply-input></textarea>
                    <p class="field-error" data-reply-error hidden></p>
                </div>

                <button type="submit" class="btn btn--primary chat-reply__send" data-reply-send>Enviar</button>

                <p class="chat-reply__hint muted muted--sm" data-reply-hint hidden>
                    Enter envía · Shift+Enter salta de línea
                </p>
            </form>

        </section>

        <aside class="workspace__contact">
            @include('agent.partials.contact-panel', [
                'conversation' => $conversation,
                'clinic' => $clinic,
                'agents' => $agents,
            ])
        </aside>
    </div>
@endsection

{{--
    Listado lateral de conversaciones.
    Se refresca por polling desde GET /agent/conversations/sidebar (devuelve este mismo parcial como HTML).
    Requiere: $conversations, $filter y opcionalmente $activeId.
--}}
@php
    $activeId = $activeId ?? null;
@endphp

<div class="conv-list" id="conv-list">
    @forelse ($conversations as $conversation)
        <a class="conv-item @if ($activeId === $conversation->id) is-active @endif"
           href="{{ route('agent.conversations.show', ['conversation' => $conversation->id, 'filter' => $filter]) }}">
            <span class="conv-item__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($conversation->displayName(), 0, 2)) }}</span>

            <span class="conv-item__body">
                <span class="conv-item__top">
                    <strong>{{ $conversation->displayName() }}</strong>
                    <time>{{ ($conversation->last_message_at ?? $conversation->updated_at)->format('d/m H:i') }}</time>
                </span>

                <span class="conv-item__preview">
                    @if ($conversation->lastMessage !== null)
                        <em>{{ $conversation->lastMessage->senderLabel() }}:</em>
                        {{ \Illuminate\Support\Str::limit($conversation->lastMessage->message, 44) }}
                    @else
                        Sin mensajes todavía.
                    @endif
                </span>

                <span class="conv-item__meta">
                    @include('agent.partials.state-badge', ['state' => $conversation->state])

                    @php($waitingLabel = $conversation->waitingTimeLabel())
                    @if ($waitingLabel !== null)
                        <span class="chip chip--warn" title="Tiempo en la cola de espera">Esperando {{ $waitingLabel }}</span>
                    @endif

                    @if ($conversation->assignedAgent !== null)
                        <span class="chip">{{ $conversation->assignedAgent->name }}</span>
                    @endif

                    @if (($conversation->unread_messages_count ?? 0) > 0)
                        <span class="unread" title="Mensajes sin leer">{{ $conversation->unread_messages_count }}</span>
                    @endif
                </span>
            </span>
        </a>
    @empty
        <p class="empty">No hay conversaciones en este filtro.</p>
    @endforelse
</div>

@include('agent.partials.pagination', ['paginator' => $conversations])

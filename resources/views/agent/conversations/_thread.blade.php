{{--
    Hilo de mensajes de una conversación.
    Se refresca por polling desde GET /agent/conversations/{id}/thread.
    Requiere: $messages (colección de App\Models\Message).
    Incluye separadores de día ("Hoy" / "Ayer" / dd/mm/aaaa) entre días distintos.
--}}
@php
    $previousDay = null;
@endphp

@forelse ($messages as $message)
    @php
        $sender = $message->senderEnum();
        $day = $message->created_at?->toDateString();
        $dayLabel = null;

        if ($day !== null && $day !== $previousDay) {
            $dayLabel = $message->created_at->isToday()
                ? 'Hoy'
                : ($message->created_at->isYesterday() ? 'Ayer' : $message->created_at->format('d/m/Y'));

            $previousDay = $day;
        }
    @endphp

    @if ($dayLabel !== null)
        <p class="msg-day">{{ $dayLabel }}</p>
    @endif

    @if ($message->isSystem())
        <article class="msg msg--system">
            <p class="msg__text">{{ $message->message }}</p>
            <time class="msg__time">{{ $message->created_at?->format('d/m/Y H:i') }}</time>
        </article>
    @else
        <article class="msg msg--{{ $sender->value }}">
            <header class="msg__meta">
                <strong>
                    @if ($message->isFromAgent())
                        {{ $message->agent?->name ?? 'Agente' }}
                    @else
                        {{ $sender->label() }}
                    @endif
                </strong>
                <time class="msg__time">{{ $message->created_at?->format('d/m/Y H:i') }}</time>
            </header>
            <p class="msg__text">{{ $message->message }}</p>
        </article>
    @endif
@empty
    <p class="empty">Todavía no hay mensajes en esta conversación.</p>
@endforelse

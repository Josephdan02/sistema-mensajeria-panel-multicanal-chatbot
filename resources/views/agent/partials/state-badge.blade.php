{{-- Insignia de estado de conversación. Requiere $state (App\Enums\ConversationState). --}}
@php($modifier = str_replace('_', '-', $state->value))
<span class="badge badge--{{ $modifier }}">{{ $state->label() }}</span>

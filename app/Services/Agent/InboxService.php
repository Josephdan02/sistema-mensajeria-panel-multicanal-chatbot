<?php

namespace App\Services\Agent;

use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas de la bandeja de entrada del centro de agentes.
 *
 * Es un servicio de solo lectura: no modifica conversaciones ni mensajes.
 * Reutiliza los scopes agregados en App\Models\Conversation.
 */
class InboxService
{
    public const FILTER_WAITING = 'waiting';

    public const FILTER_OPEN = 'open';

    public const FILTER_MINE = 'mine';

    public const FILTER_ASSIGNED = 'assigned';

    public const FILTER_ATTENDING = 'attending';

    public const FILTER_CLOSED = 'closed';

    public const FILTER_BOT = 'bot';

    /** Bot activo sin conversación en curso (idle), distinguible de `in_progress`. */
    public const FILTER_IDLE = 'idle';

    public const FILTER_ALL = 'all';

    /**
     * Filtros disponibles en la interfaz.
     *
     * @return array<string, string>
     */
    public static function filters(): array
    {
        return [
            self::FILTER_WAITING => 'Esperando agente',
            self::FILTER_OPEN => 'Conversaciones abiertas',
            self::FILTER_MINE => 'Mis conversaciones',
            self::FILTER_ASSIGNED => 'Asignadas',
            self::FILTER_ATTENDING => 'En atención',
            self::FILTER_CLOSED => 'Cerradas',
            self::FILTER_BOT => 'Atendidas por el bot',
            self::FILTER_IDLE => 'Bot: sin conversación',
            self::FILTER_ALL => 'Todas',
        ];
    }

    public static function isValidFilter(string $filter): bool
    {
        return array_key_exists($filter, self::filters());
    }

    /**
     * Contadores del dashboard.
     *
     * @return array{waiting: int, assigned: int, attending: int, closed: int, bot: int, mine: int, unread: int, unread_waiting: int}
     */
    public function stats(?User $agent): array
    {
        return [
            'waiting' => $this->baseQuery()->waitingForAgent()->count(),
            'assigned' => $this->baseQuery()->where('state', ConversationState::ASSIGNED->value)->count(),
            'attending' => $this->baseQuery()->attending()->count(),
            'closed' => $this->baseQuery()->closedConversations()->count(),
            'bot' => $this->baseQuery()->handledByBot()->count(),
            'mine' => $agent === null ? 0 : $this->baseQuery()
                ->assignedTo($agent->id)
                ->whereIn('state', [
                    ConversationState::ASSIGNED->value,
                    ConversationState::ATTENDING->value,
                ])
                ->count(),
            'unread' => $this->unreadQuery()->count(),
            'unread_waiting' => $this->unreadWaitingQuery()->count(),
        ];
    }

    public function list(string $filter, string $search, ?User $agent, int $perPage = 25): LengthAwarePaginator
    {
        return $this->applyFilter($this->baseQuery(), $filter, $agent)
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $query->where('user_identifier', 'like', '%'.trim($search).'%');
            })
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Conversaciones de la cola de espera (más antiguas primero). */
    public function waiting(int $limit = 5)
    {
        return $this->baseQuery()
            ->waitingForAgent()
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    /** Mis conversaciones activas (más recientes primero). */
    public function mine(User $agent, int $limit = 5)
    {
        return $this->baseQuery()
            ->assignedTo($agent->id)
            ->whereIn('state', [
                ConversationState::ASSIGNED->value,
                ConversationState::ATTENDING->value,
            ])
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    private function applyFilter(Builder $query, string $filter, ?User $agent): Builder
    {
        return match ($filter) {
            self::FILTER_WAITING => $query->waitingForAgent(),
            self::FILTER_OPEN => $query->whereIn('state', [
                ConversationState::HANDOFF_TO_HUMAN->value,
                ConversationState::WAITING_AGENT->value,
                ConversationState::ASSIGNED->value,
                ConversationState::ATTENDING->value,
            ]),
            self::FILTER_MINE => $query
                ->assignedTo($agent?->id ?? 0)
                ->whereIn('state', [
                    ConversationState::ASSIGNED->value,
                    ConversationState::ATTENDING->value,
                ]),
            self::FILTER_ASSIGNED => $query
                ->where('state', ConversationState::ASSIGNED->value)
                ->whereNotNull('assigned_agent_id'),
            self::FILTER_ATTENDING => $query->attending(),
            self::FILTER_CLOSED => $query->closedConversations(),
            self::FILTER_BOT => $query->handledByBot(),
            self::FILTER_IDLE => $query->where('state', ConversationState::IDLE->value),
            default => $query,
        };
    }

    /**
     * @return Builder<Conversation>
     */
    private function baseQuery(): Builder
    {
        return Conversation::query()
            ->with(['assignedAgent:id,name', 'lastMessage'])
            ->withCount([
                'messages as messages_count',
                'messages as unread_messages_count' => function (Builder $query): void {
                    $query->where('sender', Message::SENDER_USER)->whereNull('read_at');
                },
            ]);
    }

    /**
     * Mensajes del cliente pendientes de un agente humano.
     *
     * @return Builder<Message>
     */
    private function unreadQuery(): Builder
    {
        return Message::query()
            ->unread()
            ->whereHas('conversation', function (Builder $query): void {
                $query->whereIn('state', [
                    ConversationState::HANDOFF_TO_HUMAN->value,
                    ConversationState::WAITING_AGENT->value,
                    ConversationState::ASSIGNED->value,
                    ConversationState::ATTENDING->value,
                ]);
            });
    }

    /**
     * Mensajes del cliente pendientes en conversaciones en cola de espera.
     *
     * @return Builder<Message>
     */
    private function unreadWaitingQuery(): Builder
    {
        return Message::query()
            ->unread()
            ->whereHas('conversation', function (Builder $query): void {
                $query->waitingForAgent();
            });
    }
}

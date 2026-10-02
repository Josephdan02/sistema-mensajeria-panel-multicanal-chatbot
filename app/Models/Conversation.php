<?php

namespace App\Models;

use App\Enums\ConversationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    /**
     * Valores por defecto que reflejan los defaults de la tabla.
     *
     * `state` lo aplica MySQL (default 'idle'), pero Eloquent no lo conoce en
     * instancias recién creadas: sin esto, `new Conversation` tendría
     * `state = null` en memoria hasta refrescar desde la base de datos.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'idle',
        'channel' => 'web',
    ];

    protected $fillable = [
        'user_identifier',
        'state',
        'context',
        'channel',
        'assigned_agent_id',
        'assigned_at',
        'closed_at',
        'closed_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'context' => 'array',
            'assigned_agent_id' => 'integer',
            'closed_by' => 'integer',
            'assigned_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context ?? [], $key, $default);
    }

    public function setContextValue(string $key, mixed $value): void
    {
        $context = $this->context ?? [];
        data_set($context, $key, $value);
        $this->context = $context;
    }

    public function clearContext(): void
    {
        $this->context = [];
    }

    /*
    |--------------------------------------------------------------------------
    | Sistema de agentes
    |--------------------------------------------------------------------------
    */

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** Mensajes del cliente que ningún agente ha leído todavía. */
    public function unreadMessagesCount(): int
    {
        return $this->messages()
            ->where('sender', Message::SENDER_USER)
            ->whereNull('read_at')
            ->count();
    }

    public function isClosed(): bool
    {
        return $this->state->isClosed();
    }

    public function hasAssignedAgent(): bool
    {
        return $this->assigned_agent_id !== null;
    }

    /**
     * Tiempo que la conversación lleva en la cola de espera de un agente.
     *
     * Se calcula en español y sin depender del locale de Carbon para que el
     * inbox muestre siempre el mismo formato.
     */
    public function waitingTimeLabel(): ?string
    {
        if (! $this->state->isWaitingForAgent()) {
            return null;
        }

        $since = $this->last_message_at ?? $this->updated_at;

        if ($since === null) {
            return null;
        }

        $minutes = max(0, (int) $since->diffInMinutes(now()));

        return match (true) {
            $minutes < 1 => 'menos de un minuto',
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => ((int) round($minutes / 60)).' h',
            default => ((int) round($minutes / 1440)).' días',
        };
    }

    public function displayName(): string
    {
        return (string) $this->user_identifier;
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'whatsapp' => 'WhatsApp',
            'web' => 'Web',
            default => (string) $this->channel,
        };
    }

    /**
     * Identificadores clínicos guardados por el chatbot en `context.slots`.
     * El agente los usa para consultar CABANILLAS vía API (no se copian datos).
     *
     * @return array{patient_id: int|null, document: string|null, appointment_id: int|null}
     */
    public function clinicIdentifiers(): array
    {
        $patientId = $this->getContextValue('slots.paciente_id');
        $document = $this->getContextValue('slots.patient_document');
        $appointmentId = $this->getContextValue('slots.appointment_id');

        return [
            'patient_id' => is_numeric($patientId) ? (int) $patientId : null,
            'document' => is_scalar($document) && trim((string) $document) !== '' ? trim((string) $document) : null,
            'appointment_id' => is_numeric($appointmentId) ? (int) $appointmentId : null,
        ];
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeWaitingForAgent(Builder $query): Builder
    {
        return $query->whereIn('state', [
            ConversationState::HANDOFF_TO_HUMAN->value,
            ConversationState::WAITING_AGENT->value,
        ]);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeHandledByBot(Builder $query): Builder
    {
        return $query->whereIn('state', [
            ConversationState::IDLE->value,
            ConversationState::IN_PROGRESS->value,
        ]);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeAssignedTo(Builder $query, int $agentId): Builder
    {
        return $query->where('assigned_agent_id', $agentId);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeAttending(Builder $query): Builder
    {
        return $query->where('state', ConversationState::ATTENDING->value);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeClosedConversations(Builder $query): Builder
    {
        return $query->where('state', ConversationState::CLOSED->value);
    }
}

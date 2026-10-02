<?php

namespace App\Models;

use App\Enums\MessageSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const SENDER_USER = 'user';
    public const SENDER_BOT = 'bot';

    /** Orígenes persistidos agregados por el sistema de agentes. */
    public const SENDER_AGENT = 'agent';
    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id',
        'sender',
        'message',
        'intent',
        'agent_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function senderEnum(): MessageSender
    {
        return MessageSender::tryFrom((string) $this->sender) ?? MessageSender::SYSTEM;
    }

    public function senderLabel(): string
    {
        return $this->senderEnum()->label();
    }

    public function isFromCustomer(): bool
    {
        return $this->sender === self::SENDER_USER;
    }

    public function isFromAgent(): bool
    {
        return $this->sender === self::SENDER_AGENT;
    }

    public function isFromBot(): bool
    {
        return $this->sender === self::SENDER_BOT;
    }

    public function isSystem(): bool
    {
        return $this->sender === self::SENDER_SYSTEM;
    }

    /**
     * Mensajes del cliente que todavía no ha leído un agente.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('sender', self::SENDER_USER)->whereNull('read_at');
    }
}

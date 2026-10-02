<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_AGENT = 'agent';

    public const ROLE_SUPERVISOR = 'supervisor';

    /**
     * Roles con acceso al centro de agentes (/agent/*).
     *
     * @var list<string>
     */
    public const AGENT_ROLES = [self::ROLE_AGENT, self::ROLE_SUPERVISOR];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'active',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Sistema de agentes
    |--------------------------------------------------------------------------
    */

    public function isAgent(): bool
    {
        return in_array($this->role, self::AGENT_ROLES, true);
    }

    public function isSupervisor(): bool
    {
        return $this->role === self::ROLE_SUPERVISOR;
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function canAccessAgentPanel(): bool
    {
        return $this->isAgent() && $this->isActive();
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_AGENT => 'Agente',
            self::ROLE_SUPERVISOR => 'Supervisor',
            default => 'Sin rol de agente',
        };
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'AG';
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_agent_id');
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeAgents(Builder $query): Builder
    {
        return $query->whereIn('role', self::AGENT_ROLES);
    }
}

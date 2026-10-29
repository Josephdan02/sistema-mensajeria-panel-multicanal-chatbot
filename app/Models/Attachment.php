<?php

namespace App\Models;

use App\Enums\AttachmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadatos de un adjunto recibido por un canal externo (Telegram).
 *
 * Nunca se guardan los bytes del archivo en la base de datos: solo la ruta
 * privada del objeto dentro del bucket privado de Supabase Storage.
 */
class Attachment extends Model
{
    protected $fillable = [
        'message_id',
        'conversation_id',
        'channel',
        'telegram_file_id',
        'telegram_file_unique_id',
        'kind',
        'original_name',
        'extension',
        'mime_type',
        'size',
        'status',
        'storage_path',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttachmentStatus::class,
            'size' => 'integer',
            'message_id' => 'integer',
            'conversation_id' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isAvailable(): bool
    {
        return $this->status->isAvailable();
    }

    public function hasError(): bool
    {
        return $this->status->hasError();
    }

    /** Tamaño legible para el panel (KB / MB). */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 2).' MB';
    }

    /**
     * @param  Builder<Attachment>  $query
     * @return Builder<Attachment>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', AttachmentStatus::AVAILABLE->value);
    }

    /**
     * @param  Builder<Attachment>  $query
     * @return Builder<Attachment>
     */
    public function scopeForTelegramFile(Builder $query, string $fileId): Builder
    {
        return $query->where('telegram_file_id', $fileId);
    }
}

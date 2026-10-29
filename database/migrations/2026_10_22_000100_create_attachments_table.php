<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadatos de los adjuntos recibidos por los canales externos (Telegram).
 *
 * - No se modifica la tabla `messages`: el adjunto se relaciona con el
 *   mensaje mediante `message_id` (1:1) para no alterar el comportamiento de
 *   los mensajes de texto.
 * - Nunca se guardan los bytes del archivo: solo la ruta privada del objeto
 *   dentro del bucket privado de Supabase Storage.
 * - `telegram_file_id` es único para poder recuperar el archivo y evitar
 *   adjuntos duplicados cuando Telegram reenvía la misma actualización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('channel', 32)->default('telegram');
            $table->string('telegram_file_id')->nullable();
            $table->string('telegram_file_unique_id', 128)->nullable();
            $table->string('kind', 32);
            $table->string('original_name')->nullable();
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->string('storage_path', 512)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['channel', 'telegram_file_id']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};

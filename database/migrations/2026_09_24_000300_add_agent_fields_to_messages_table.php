<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del sistema de agentes para la tabla `messages`.
 *
 * `sender` sigue aceptando los valores históricos `user` y `bot`; el sistema de
 * agentes agrega de forma persistida `agent` y `system` (ver App\Enums\MessageSender).
 * `read_at` permite calcular los mensajes no leídos por los agentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->dropColumn(['agent_id', 'read_at']);
        });
    }
};

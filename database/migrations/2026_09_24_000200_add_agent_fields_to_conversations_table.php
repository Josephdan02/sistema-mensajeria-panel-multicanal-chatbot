<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del sistema de agentes para la tabla `conversations`.
 *
 * Todas las columnas son nuevas y nullable (excepto `channel`, que tiene un
 * valor por defecto), por lo que las conversaciones existentes siguen siendo
 * válidas y el chatbot continúa funcionando igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->string('channel')->default('web');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['assigned_agent_id']);
            $table->dropForeign(['closed_by']);
            $table->dropIndex(['state']);
            $table->dropColumn([
                'assigned_agent_id',
                'assigned_at',
                'closed_at',
                'closed_by',
                'last_message_at',
                'channel',
            ]);
        });
    }
};

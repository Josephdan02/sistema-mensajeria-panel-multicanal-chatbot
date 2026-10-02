<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del sistema de agentes para la tabla `users`.
 *
 * Son aditivos y opcionales para no afectar a los usuarios existentes:
 * `role = null` significa "usuario que no es agente" (por ejemplo el usuario
 * creado por el seeder original o cualquier usuario previo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'active', 'last_seen_at']);
        });
    }
};

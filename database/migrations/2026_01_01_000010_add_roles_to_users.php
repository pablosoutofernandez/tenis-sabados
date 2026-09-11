<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles y vinculación de cuentas con jugadores del torneo.
     *
     *   admin       → puede todo, incluido gestionar usuarios y ver auditoría
     *   organizador → monta jornadas, pero NO ve puntuaciones ni clasificación
     *   jugador     → ve la clasificación y publica el resultado de SUS partidos
     *
     * Las cuentas nuevas entran como 'jugador' e inactivas: el admin las
     * aprueba y las vincula al jugador del torneo que corresponda. Así nadie
     * que encuentre la URL de registro entra solo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('rol', 20)->default('jugador')->after('email');
            $table->foreignId('jugador_id')->nullable()->unique()->after('rol')
                ->constrained('jugadores')->nullOnDelete();
            $table->boolean('activo')->default(false)->after('jugador_id');
            $table->boolean('debe_cambiar_password')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jugador_id');
            $table->dropColumn(['rol', 'activo', 'debe_cambiar_password']);
        });
    }
};

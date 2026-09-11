<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->unsignedTinyInteger('pista')->default(1);
            $table->time('hora')->nullable();
            // Sets ganados por cada pareja. NULL = todavía sin resultado.
            $table->unsignedTinyInteger('sets_a')->nullable();
            $table->unsignedTinyInteger('sets_b')->nullable();
            // Jugador que se retiró por lesión u otro problema (norma del torneo).
            $table->foreignId('retirado_id')->nullable()->constrained('jugadores')->nullOnDelete();
            $table->boolean('super_tie_break')->default(false);
            // Por qué la IA formó así este partido.
            $table->text('motivo_ia')->nullable();
            $table->timestamps();
        });

        Schema::create('partido_jugador', function (Blueprint $table) {
            $table->foreignId('partido_id')->constrained('partidos')->cascadeOnDelete();
            $table->foreignId('jugador_id')->constrained('jugadores')->cascadeOnDelete();
            $table->char('equipo', 1);                          // a | b
            $table->unsignedTinyInteger('puntos')->default(0);  // 1 por set ganado, máx. 3
            $table->primary(['partido_id', 'jugador_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partido_jugador');
        Schema::dropIfExists('partidos');
    }
};

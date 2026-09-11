<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Puntuación de partida por jugador y año, al margen de los partidos
     * jugados en la app. Sirve para arrancar con lo que ya llevabais antes
     * de usarla, o para corregir la clasificación a mano si hace falta.
     */
    public function up(): void
    {
        Schema::create('ajustes_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jugador_id')->constrained('jugadores')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->integer('puntos')->default(0);
            $table->timestamps();
            $table->unique(['jugador_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_puntos');
    }
};

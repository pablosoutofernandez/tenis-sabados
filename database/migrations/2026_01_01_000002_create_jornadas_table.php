<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una jornada = un sábado de torneo (2 o 3 partidos de dobles).
        Schema::create('jornadas', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->unsignedTinyInteger('pistas')->default(3);
            // borrador | publicada | finalizada
            $table->string('estado', 20)->default('borrador');
            // Razonamiento que devolvió la IA al hacer los emparejamientos.
            $table->text('explicacion_ia')->nullable();
            $table->timestamps();
        });

        // Quién está disponible ese sábado. "fuera" = disponible pero sin hueco,
        // porque los disponibles no daban para una pista más.
        Schema::create('jornada_jugador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jornada_id')->constrained('jornadas')->cascadeOnDelete();
            $table->foreignId('jugador_id')->constrained('jugadores')->cascadeOnDelete();
            $table->boolean('fuera')->default(false);
            $table->unique(['jornada_id', 'jugador_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jornada_jugador');
        Schema::dropIfExists('jornadas');
    }
};

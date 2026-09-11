<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una sola fila con las prioridades que el organizador le da a la IA
     * al montar una jornada, y sus notas generales (a diferencia de las
     * de notas_ia, que son correcciones puntuales de un partido concreto,
     * estas son preferencias fijas que no caducan).
     */
    public function up(): void
    {
        Schema::create('ajustes_ia', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('prioridad_equilibrio')->default(3);
            $table->unsignedTinyInteger('prioridad_no_repetir')->default(3);
            $table->unsignedTinyInteger('prioridad_frenar_lider')->default(3);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes_ia');
    }
};

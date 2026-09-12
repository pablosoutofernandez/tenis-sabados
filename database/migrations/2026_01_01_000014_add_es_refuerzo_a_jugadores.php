<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jugador de refuerzo: alguien que completa una pista cuando falta
     * gente, pero no compite por la clasificación de la temporada. Se le
     * aplica el Elo igual que a cualquiera (el nivel sí tiene que ser
     * fiable para repartir bien), pero no sale en la tabla de puntos ni
     * se marca disponible por defecto al montar jornada.
     */
    public function up(): void
    {
        Schema::table('jugadores', function (Blueprint $table) {
            $table->boolean('es_refuerzo')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('jugadores', function (Blueprint $table) {
            $table->dropColumn('es_refuerzo');
        });
    }
};

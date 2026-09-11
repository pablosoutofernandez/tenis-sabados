<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prepara la base para el ajuste automático de nivel al estilo Elo.
     *
     * No hace falta tocar el tipo de la columna 'nivel': está en cast
     * 'float' en el modelo, así que SQLite ya guarda el valor completo sin
     * truncar precisión — el decimal(3,1) original era solo una etiqueta.
     *
     * Sí hace falta 'nivel_delta' en partido_jugador: cuánto se movió el
     * nivel de cada jugador por ESTE partido en concreto, para poder
     * revertirlo si se borra o corrige el resultado más adelante.
     */
    public function up(): void
    {
        Schema::table('partido_jugador', function (Blueprint $table) {
            $table->decimal('nivel_delta', 5, 2)->default(0)->after('puntos');
        });
    }

    public function down(): void
    {
        Schema::table('partido_jugador', function (Blueprint $table) {
            $table->dropColumn('nivel_delta');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Antes solo se guardaba "quién ganó cuántos sets" (2-0, 2-1...). Para
     * que el Elo distingua un 6-0 6-0 de un 7-6 7-6 hace falta el marcador
     * de cada set — así que se guarda también el detalle completo.
     *
     * 'sets_a' y 'sets_b' se quedan (los sigue usando la regla de puntos
     * del torneo: 1 punto por set ganado), pero ahora se calculan a partir
     * de 'detalle_sets', no se piden aparte.
     */
    public function up(): void
    {
        Schema::table('partidos', function (Blueprint $table) {
            $table->json('detalle_sets')->nullable()->after('sets_b');
        });
    }

    public function down(): void
    {
        Schema::table('partidos', function (Blueprint $table) {
            $table->dropColumn('detalle_sets');
        });
    }
};

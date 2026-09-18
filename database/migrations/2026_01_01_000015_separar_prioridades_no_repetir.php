<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Variedad" era un único ajuste que gobernaba a la vez el no repetir
     * parejas y el no repetir rivales, así que no había forma de apretar
     * los cruces sin apretar también las parejas. Se parte en dos.
     *
     * El valor que hubiera puesto se copia tal cual en los dos ajustes
     * nuevos: quien ya tenía la variedad al 4 se queda con parejas 4 y
     * rivales 4, y no nota nada raro hasta que decida separarlos.
     */
    public function up(): void
    {
        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->unsignedTinyInteger('prioridad_no_repetir_parejas')->default(3)->after('prioridad_equilibrio');
            $table->unsignedTinyInteger('prioridad_no_repetir_rivales')->default(3)->after('prioridad_no_repetir_parejas');
        });

        DB::table('ajustes_ia')->update([
            'prioridad_no_repetir_parejas' => DB::raw('prioridad_no_repetir'),
            'prioridad_no_repetir_rivales' => DB::raw('prioridad_no_repetir'),
        ]);

        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->dropColumn('prioridad_no_repetir');
        });
    }

    public function down(): void
    {
        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->unsignedTinyInteger('prioridad_no_repetir')->default(3)->after('prioridad_equilibrio');
        });

        // Al volver atrás se pierde la distinción: nos quedamos con el de
        // parejas, que es el que más peso tenía en el comportamiento viejo.
        DB::table('ajustes_ia')->update([
            'prioridad_no_repetir' => DB::raw('prioridad_no_repetir_parejas'),
        ]);

        Schema::table('ajustes_ia', function (Blueprint $table) {
            $table->dropColumn(['prioridad_no_repetir_parejas', 'prioridad_no_repetir_rivales']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Limpieza puntual, por si alguna jornada se borró antes de este cambio
     * (cuando el borrado no revertía el Elo ni limpiaba en cascada de forma
     * explícita — ver App\Models\Jornada::booted()). Busca cualquier
     * partido_jugador huérfano (su partido ya no existe), revierte el
     * ajuste de nivel que hubiera quedado aplicado "fantasma", y borra los
     * restos de partidos y jornada_jugador que ya no tengan jornada.
     *
     * Si tu base de datos no tenía huérfanos (lo normal si las claves
     * foráneas estaban activadas), esto no hace nada.
     */
    public function up(): void
    {
        $huerfanos = DB::table('partido_jugador')
            ->whereNotIn('partido_id', function ($q) {
                $q->select('id')->from('partidos');
            })
            ->get();

        foreach ($huerfanos as $fila) {
            if ((float) $fila->nivel_delta !== 0.0) {
                DB::table('jugadores')
                    ->where('id', $fila->jugador_id)
                    ->increment('nivel', -$fila->nivel_delta);
            }
        }

        DB::table('partido_jugador')
            ->whereNotIn('partido_id', function ($q) {
                $q->select('id')->from('partidos');
            })
            ->delete();

        DB::statement('DELETE FROM partidos WHERE jornada_id NOT IN (SELECT id FROM jornadas)');
        DB::statement('DELETE FROM jornada_jugador WHERE jornada_id NOT IN (SELECT id FROM jornadas)');
    }

    public function down(): void
    {
        // No reversible: lo que borra era basura huérfana, no había nada
        // legítimo que recuperar.
    }
};

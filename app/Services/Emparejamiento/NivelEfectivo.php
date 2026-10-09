<?php

namespace App\Services\Emparejamiento;

use App\Models\Jugador;

/**
 * Nivel "para repartir" de cada convocado: el real, salvo un plus para el
 * líder en puntos de los convocados de hoy, que lo hace contar como más
 * fuerte al cuadrar las sumas (y así le tocan partidos más difíciles).
 *
 * El plus crece con la ventaja que le saca al segundo, hasta el máximo del
 * ajuste cuando la ventaja llega a Pesos::VENTAJA_PLENA_LIDER. Con empate en
 * cabeza (por ejemplo, a principio de temporada) no hay plus para nadie.
 * Los refuerzos no compiten por la temporada: ni son líderes ni cuentan
 * como segundo.
 */
final class NivelEfectivo
{
    /**
     * @param  array<int>  $jugadorIds
     * @return array{niveles: array<int, float>, lider: ?int, plus: float}
     */
    public static function calcular(array $jugadorIds, int $anio, Pesos $pesos): array
    {
        $jugadores = Jugador::whereIn('id', $jugadorIds)->get(['id', 'nivel', 'es_refuerzo'])->keyBy('id');

        $niveles = [];
        foreach ($jugadorIds as $id) {
            $niveles[(int) $id] = (float) ($jugadores[$id]->nivel ?? 5.0);
        }

        [$lider, $plus] = self::lider($jugadores->reject->es_refuerzo->keys()->all(), $anio, $pesos);

        if ($lider !== null) {
            $niveles[$lider] = round($niveles[$lider] + $plus, 2);
        }

        return ['niveles' => $niveles, 'lider' => $lider, 'plus' => $plus];
    }

    /** @return array{0: ?int, 1: float} */
    private static function lider(array $candidatos, int $anio, Pesos $pesos): array
    {
        if ($pesos->plusLider <= 0 || $candidatos === []) {
            return [null, 0.0];
        }

        $puntos = Jugador::puntosPorJugador($anio);
        $orden  = collect($candidatos)
            ->mapWithKeys(fn ($id) => [(int) $id => (int) ($puntos[$id] ?? 0)])
            ->sortDesc();

        $primero = $orden->values()->get(0, 0);
        $segundo = $orden->values()->get(1, 0);
        $ventaja = $primero - $segundo;

        if ($ventaja <= 0) {
            return [null, 0.0];
        }

        $plus = $pesos->plusLider * min(1.0, $ventaja / Pesos::VENTAJA_PLENA_LIDER);

        return [(int) $orden->keys()->first(), round($plus, 2)];
    }
}

<?php

namespace App\Services;

use App\Models\Jugador;
use App\Models\Partido;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a jugar toda una temporada con el Elo actual: parte del nivel que
 * cada jugador tenía antes de su primer partido con ajuste de ese año y
 * aplica los resultados en orden de fecha.
 *
 * Por defecto es más suave que el Elo en vivo: k se multiplica por
 * tenis.elo.factor_recalculo, porque reescribir de golpe una temporada
 * entera con k completo movía demasiado los niveles a los que ya estaba
 * acostumbrado el grupo.
 *
 * El nivel de partida se reconstruye restando al nivel de hoy los ajustes
 * anotados en los partidos del año. Si algún resultado se corrigió con el
 * Elo antiguo, aquel ajuste se sumó dos veces y solo quedó anotado el
 * último, así que ese exceso sigue dentro del nivel de partida: no hay
 * forma de recuperarlo.
 */
class RecalculoNiveles
{
    public function __construct(private EloNiveles $elo)
    {
    }

    /**
     * @return array{
     *     jugadores: array<int, array{nombre: string, inicial: float, actual: float, nuevo: float}>,
     *     partidos: array<int, array<int, float>>,  [partido_id => [jugador_id => delta]]
     * }
     */
    public function calcular(int $anio, ?float $factorK = null): array
    {
        $factorK ??= (float) config('tenis.elo.factor_recalculo', 0.6);

        $partidos = Partido::with('jugadores', 'jornada')
            ->join('jornadas', 'jornadas.id', '=', 'partidos.jornada_id')
            ->whereYear('jornadas.fecha', $anio)
            ->whereNotNull('partidos.detalle_sets')
            ->orderBy('jornadas.fecha')->orderBy('partidos.pista')
            ->select('partidos.*')
            ->get();

        $deltasDelAnio = $partidos->flatMap(fn (Partido $p) => $p->jugadores)
            ->groupBy('id')
            ->map(fn ($filas) => $filas->sum(fn ($j) => (float) $j->pivot->nivel_delta));

        $jugadores = Jugador::whereIn('id', $deltasDelAnio->keys())->get()->mapWithKeys(fn (Jugador $j) => [
            $j->id => [
                'nombre'  => $j->nombre,
                'inicial' => round($j->nivel - $deltasDelAnio[$j->id], 2),
                'actual'  => $j->nivel,
            ],
        ])->all();

        $nivel   = array_map(fn ($j) => $j['inicial'], $jugadores);
        $previos = [];
        $nuevos  = [];

        foreach ($partidos as $partido) {
            $a = $partido->equipo('a')->pluck('id');
            $b = $partido->equipo('b')->pluck('id');

            $deltas = [];
            if ($partido->retirado_id === null && $a->count() === 2 && $b->count() === 2) {
                $deltas = $this->elo->deltas(
                    $a->mapWithKeys(fn ($id) => [$id => $nivel[$id]])->all(),
                    $b->mapWithKeys(fn ($id) => [$id => $nivel[$id]])->all(),
                    $partido->detalle_sets,
                    $previos,
                    $factorK,
                );
            }

            // Primero se calculan los 4 con los niveles de antes del partido
            // y luego se aplican, igual que en vivo.
            foreach ($deltas as $id => $delta) {
                $nivel[$id] += $delta;
                $previos[$id] = ($previos[$id] ?? 0) + 1;
            }

            $nuevos[$partido->id] = $deltas;
        }

        foreach ($jugadores as $id => &$j) {
            $j['nuevo'] = round($nivel[$id], 2);
        }

        return ['jugadores' => $jugadores, 'partidos' => $nuevos];
    }

    /** Guarda un recálculo: niveles finales y el ajuste de cada partido. */
    public function guardar(array $recalculo): void
    {
        DB::transaction(function () use ($recalculo) {
            foreach ($recalculo['jugadores'] as $id => $j) {
                Jugador::whereKey($id)->update(['nivel' => $j['nuevo']]);
            }

            foreach ($recalculo['partidos'] as $partidoId => $deltas) {
                DB::table('partido_jugador')->where('partido_id', $partidoId)->update(['nivel_delta' => 0]);

                foreach ($deltas as $jugadorId => $delta) {
                    DB::table('partido_jugador')
                        ->where('partido_id', $partidoId)
                        ->where('jugador_id', $jugadorId)
                        ->update(['nivel_delta' => $delta]);
                }
            }
        });
    }
}

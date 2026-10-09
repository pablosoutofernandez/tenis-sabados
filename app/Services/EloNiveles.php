<?php

namespace App\Services;

use App\Models\Partido;
use Illuminate\Support\Facades\DB;

/**
 * Ajusta el nivel de los 4 jugadores de un partido después de cada
 * resultado, al estilo Elo: compara lo que "tocaba" según la suma de nivel
 * de cada pareja con lo que pasó de verdad, y mueve el nivel según la
 * sorpresa.
 *
 * Cada set (y el súper tie-break, con medio peso) es una prueba aparte:
 *
 *   dominio del set  = ½ · % de juegos del set + ½ · (1 si se ganó, 0 si no)
 *   esperado del set = ½ · % de juegos esperado + ½ · prob. de ganar un set
 *   sorpresa         = Σ (dominio − esperado) de todos los sets
 *
 * Así cuenta lo apretado de cada set (un 6-0 no es un 7-6) y también
 * cuántos sets se ganan: un 4-0 (3-0 más súper) suma el doble de pruebas
 * que un 2-0, y un 2-1 suma lo ganado menos lo perdido. Si cada set sale
 * justo como se esperaba, no se mueve nada. (Antes se comparaba la
 * probabilidad de ganar el set con el % de juegos: el favorito que
 * cumplía lo previsto bajaba y los niveles se apelotonaban en el centro.)
 *
 * Cuánto se mueve cada jugador:  k × sorpresa / 2, con tope por partido.
 * k (por set) es alto en los primeros partidos de la temporada de cada
 * jugador (periodo provisional: aún no sabemos su nivel) y bajo después.
 * Por eso el ajuste no es necesariamente simétrico entre las dos parejas.
 *
 * Los partidos con retirada no mueven nivel: el marcador no refleja mérito
 * real. El nivel no tiene techo ni suelo; el tope es por partido.
 */
class EloNiveles
{
    /**
     * Aplica el ajuste y lo anota en el pivote de cada jugador
     * (partido_jugador.nivel_delta) para poder revertirlo. Si el partido ya
     * tenía un ajuste (se está corrigiendo el resultado), lo deshace antes.
     */
    public function aplicar(Partido $partido): void
    {
        $this->revertir($partido);

        if ($partido->retirado_id !== null || empty($partido->detalle_sets)) {
            return;
        }

        $partido->load('jugadores', 'jornada');
        $equipoA = $partido->equipo('a');
        $equipoB = $partido->equipo('b');

        if ($equipoA->count() !== 2 || $equipoB->count() !== 2) {
            return;
        }

        $deltas = $this->deltas(
            $equipoA->pluck('nivel', 'id')->all(),
            $equipoB->pluck('nivel', 'id')->all(),
            $partido->detalle_sets,
            $this->partidosPrevios($partido),
        );

        foreach ($deltas as $jugadorId => $delta) {
            $partido->jugadores->firstWhere('id', $jugadorId)->increment('nivel', $delta);
            $partido->jugadores()->updateExistingPivot($jugadorId, ['nivel_delta' => $delta]);
        }
    }

    /** Deshace el ajuste que se aplicó por este partido, si lo hubo. */
    public function revertir(Partido $partido): void
    {
        $partido->load('jugadores');

        foreach ($partido->jugadores as $jugador) {
            $delta = (float) $jugador->pivot->nivel_delta;

            if ($delta !== 0.0) {
                $jugador->decrement('nivel', $delta);
            }
        }

        $partido->jugadores()->updateExistingPivot(
            $partido->jugadores->pluck('id')->all(),
            ['nivel_delta' => 0],
        );
    }

    /**
     * El cálculo en sí, sin tocar la base de datos (lo usan aplicar() y el
     * recálculo de temporada).
     *
     * @param  array<int, float>  $nivelesA  [jugador_id => nivel] de la pareja A
     * @param  array<int, float>  $nivelesB  Igual, pareja B
     * @param  array<int, int>  $previos  [jugador_id => partidos con ajuste ya jugados esta temporada]
     * @return array<int, float>  [jugador_id => delta]
     */
    public function deltas(array $nivelesA, array $nivelesB, array $detalleSets, array $previos): array
    {
        $sorpresaA = $this->sorpresa($detalleSets, array_sum($nivelesA) - array_sum($nivelesB));
        $tope      = (float) config('tenis.elo.tope_por_partido', 1.0);

        $deltas = [];
        foreach ([[$nivelesA, $sorpresaA], [$nivelesB, -$sorpresaA]] as [$niveles, $sorpresa]) {
            foreach (array_keys($niveles) as $id) {
                $delta = round($this->k($previos[$id] ?? 0) * $sorpresa / 2, 2);
                $deltas[$id] = max(-$tope, min($tope, $delta));
            }
        }

        return $deltas;
    }

    /**
     * Dominio que se espera EN UN SET de la pareja con $diferencia más de
     * suma: ½ % de juegos esperado + ½ probabilidad de ganar el set.
     */
    public function esperado(float $diferencia): float
    {
        $juegos = 1 / (1 + 10 ** (-$diferencia / (float) config('tenis.elo.divisor_juegos', 10.0)));
        $sets   = 1 / (1 + 10 ** (-$diferencia / (float) config('tenis.elo.divisor_sets', 4.0)));

        return ($juegos + $sets) / 2;
    }

    /** k del periodo provisional en los primeros partidos de la temporada, k normal después. */
    public function k(int $partidosPrevios): float
    {
        return $partidosPrevios < (int) config('tenis.elo.partidos_provisional', 8)
            ? (float) config('tenis.elo.k_provisional', 1.5)
            : (float) config('tenis.elo.k', 0.4);
    }

    /**
     * Suma, set a set, cuánto mejor (positivo) o peor (negativo) le fue a la
     * pareja A de lo esperado con $diferencia más de suma. Los sets sin
     * juegos se ignoran; el súper tie-break cuenta con su peso reducido.
     *
     * @param  array<int, array{a: int, b: int, tie_break?: bool}>  $detalleSets
     */
    public function sorpresa(array $detalleSets, float $diferencia): float
    {
        $pesoSuper = (float) config('tenis.elo.peso_super_tie_break', 0.5);
        $esperado  = $this->esperado($diferencia);
        $total     = 0.0;

        foreach ($detalleSets as $set) {
            $a = (float) ($set['a'] ?? 0);
            $b = (float) ($set['b'] ?? 0);

            if ($a + $b <= 0) {
                continue;
            }

            $ganado = $a > $b ? 1.0 : ($a == $b ? 0.5 : 0.0);
            $peso   = ($set['tie_break'] ?? false) ? $pesoSuper : 1.0;
            $total += $peso * (($a / ($a + $b) + $ganado) / 2 - $esperado);
        }

        return $total;
    }

    /**
     * Partidos con ajuste de nivel (con marcador y sin retirada) que cada
     * uno de los 4 jugó esta temporada ANTES de la jornada de este partido.
     *
     * @return array<int, int>
     */
    private function partidosPrevios(Partido $partido): array
    {
        $fecha = $partido->jornada->fecha;

        return DB::table('partido_jugador as pj')
            ->join('partidos as p', 'p.id', '=', 'pj.partido_id')
            ->join('jornadas as j', 'j.id', '=', 'p.jornada_id')
            ->whereIn('pj.jugador_id', $partido->jugadores->pluck('id'))
            ->where('p.id', '!=', $partido->id)
            ->whereNotNull('p.detalle_sets')
            ->whereNull('p.retirado_id')
            ->whereYear('j.fecha', $fecha->year)
            ->where('j.fecha', '<', $fecha->toDateString())
            ->groupBy('pj.jugador_id')
            ->pluck(DB::raw('COUNT(*)'), 'pj.jugador_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}

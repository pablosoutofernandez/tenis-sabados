<?php

namespace App\Services;

use App\Models\Partido;

/**
 * Ajusta el nivel de los 4 jugadores de un partido después de cada
 * resultado, al estilo Elo: compara lo que "tocaba" ganar según la suma
 * de nivel de cada pareja con lo reñido que estuvo de verdad el partido,
 * y mueve un poco el nivel de los 4 según la sorpresa.
 *
 * Lo reñido que estuvo no se mide solo por "quién ganó cuántos sets" —
 * un 6-0 6-0 6-0 no es lo mismo que un 7-6 7-6 7-6 aunque los dos sean
 * 3-0, así que se usa el marcador de cada set (juegos, o puntos si fue
 * un súper tie-break) para calcular cuánto dominó cada pareja de verdad.
 *
 * Deliberadamente conservador: un resultado dentro de lo esperado apenas
 * mueve nada; una sorpresa grande (el favorito pierde claramente, o el
 * flojo gana con holgura) mueve algo más — con un tope fijo de medio
 * punto por partido, para que nunca sea un volantazo. El ajuste se
 * reparte a partes iguales entre los dos compañeros de cada pareja — no
 * hay forma de saber quién ganó qué punto dentro del partido, así que no
 * se intenta repartir de otra forma.
 *
 * Los partidos con retirada no mueven nivel: el marcador no refleja
 * mérito real (el rival se lleva los 3 puntos por norma, no por juego).
 *
 * No hay tope superior ni inferior en el nivel en sí: si alguien juega
 * sistemáticamente por encima de su nivel, puede superar el 10 sin
 * límite — el 10 nunca fue un techo real, solo el valor por defecto del
 * formulario. El tope de medio punto es POR PARTIDO, no un límite del
 * nivel global.
 */
class EloNiveles
{
    /**
     * Aplica el ajuste y lo deja anotado en el pivote de cada jugador
     * (partido_jugador.nivel_delta) para poder revertirlo si se borra o
     * corrige el resultado más adelante.
     */
    public function aplicar(Partido $partido): void
    {
        if ($partido->retirado_id !== null) {
            return;
        }

        $deltas = $this->calcularDeltas($partido);

        if ($deltas === null) {
            return;
        }

        foreach ($deltas as $jugadorId => $delta) {
            $partido->jugadores()->find($jugadorId)?->increment('nivel', $delta);
            $partido->jugadores()->updateExistingPivot($jugadorId, ['nivel_delta' => $delta]);
        }
    }

    /** Deshace el ajuste que se aplicó por este partido, si lo hubo. */
    public function revertir(Partido $partido): void
    {
        $partido->loadMissing('jugadores');

        foreach ($partido->jugadores as $jugador) {
            $delta = (float) $jugador->pivot->nivel_delta;

            if ($delta !== 0.0) {
                $jugador->increment('nivel', -$delta);
            }
        }

        $partido->jugadores()->updateExistingPivot(
            $partido->jugadores->pluck('id')->all(),
            ['nivel_delta' => 0],
        );
    }

    /**
     * Calcula cuánto se mueve cada uno de los 4, o null si el partido no
     * tiene datos suficientes (sin resultado, o sin sets detallados).
     *
     * @return array<int, float>|null  [jugador_id => delta]
     */
    private function calcularDeltas(Partido $partido): ?array
    {
        $detalleSets = $partido->detalle_sets;

        if (empty($detalleSets)) {
            return null;
        }

        $partido->loadMissing('jugadores');

        $equipoA = $partido->equipo('a');
        $equipoB = $partido->equipo('b');

        if ($equipoA->count() !== 2 || $equipoB->count() !== 2) {
            return null;
        }

        $sumaA = (float) $equipoA->sum('nivel');
        $sumaB = (float) $equipoB->sum('nivel');

        $divisor = (float) config('tenis.elo.divisor', 4.0);
        $k       = (float) config('tenis.elo.k', 1.0);
        $tope    = (float) config('tenis.elo.tope_por_partido', 0.5);

        $esperadoA = 1 / (1 + (10 ** (($sumaB - $sumaA) / $divisor)));
        $realA     = $this->dominanciaMedia($detalleSets);

        $deltaEquipoA    = $k * ($realA - $esperadoA);
        $deltaPorJugador = round($deltaEquipoA / 2, 2);

        // Tope duro, independiente de cómo se configuren $k o $divisor:
        // nadie sube ni baja más de esto en un partido. Como cada jugador
        // solo juega un partido por jornada, esto ya cubre "por día".
        $deltaPorJugador = max(-$tope, min($tope, $deltaPorJugador));

        $deltas = [];
        foreach ($equipoA as $jugador) {
            $deltas[$jugador->id] = $deltaPorJugador;
        }
        foreach ($equipoB as $jugador) {
            $deltas[$jugador->id] = -$deltaPorJugador;
        }

        return $deltas;
    }

    /**
     * Cuánto dominó la pareja A el partido, de 0 (arrasada) a 1 (arrasó),
     * mirando el marcador de cada set en vez de solo cuántos ganó. Un
     * 6-0 promedia cerca de 1.0 (dominio total); un 7-6 promedia cerca de
     * 0.5 (casi un empate) — así un 3-0 muy reñido no mueve lo mismo que
     * un 3-0 arrollador, aunque los dos sean "3-0" a efectos de puntos.
     *
     * El súper tie-break cuenta (sigue siendo un set ganado o perdido a
     * efectos de puntos, eso no cambia), pero pesa menos aquí: es un
     * formato corto y de más variación, así que dice menos sobre quién
     * jugó mejor que un set completo — sin dejar de contar del todo.
     *
     * @param  array<int, array{a: int, b: int, tie_break?: bool}>  $detalleSets
     */
    private function dominanciaMedia(array $detalleSets): float
    {
        $pesoSuper = (float) config('tenis.elo.peso_super_tie_break', 0.5);

        $sumaPonderada = 0.0;
        $pesoTotal     = 0.0;

        foreach ($detalleSets as $set) {
            $a = (float) ($set['a'] ?? 0);
            $b = (float) ($set['b'] ?? 0);

            if (($a + $b) <= 0) {
                continue;
            }

            $peso = ($set['tie_break'] ?? false) ? $pesoSuper : 1.0;

            $sumaPonderada += ($a / ($a + $b)) * $peso;
            $pesoTotal     += $peso;
        }

        return $pesoTotal > 0 ? $sumaPonderada / $pesoTotal : 0.5;
    }
}

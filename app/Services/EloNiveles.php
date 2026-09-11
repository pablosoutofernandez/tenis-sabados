<?php

namespace App\Services;

use App\Models\Partido;

/**
 * Ajusta el nivel de los 4 jugadores de un partido después de cada
 * resultado, al estilo Elo: compara lo que "tocaba" ganar según la suma
 * de nivel de cada pareja con lo que pasó de verdad (los sets), y mueve
 * un poco el nivel de los 4 según la sorpresa.
 *
 * Deliberadamente conservador: un resultado dentro de lo esperado apenas
 * mueve nada; una sorpresa grande (el favorito pierde claramente, o el
 * flojo gana con holgura) mueve algo más. El ajuste se reparte a partes
 * iguales entre los dos compañeros de cada pareja — no hay forma de saber
 * quién ganó qué punto dentro del partido, así que no se intenta repartir
 * de otra forma.
 *
 * Los partidos con retirada no mueven nivel: el marcador no refleja
 * mérito real (el rival se lleva los 3 puntos por norma, no por juego).
 *
 * No hay tope superior ni inferior: si alguien juega sistemáticamente
 * por encima de su nivel, puede superar el 10 sin límite — el 10 nunca
 * fue un techo real, solo el valor por defecto del formulario.
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
     * tiene datos suficientes (sin resultado, o 0-0).
     *
     * @return array<int, float>|null  [jugador_id => delta]
     */
    private function calcularDeltas(Partido $partido): ?array
    {
        $setsA = $partido->sets_a;
        $setsB = $partido->sets_b;

        if ($setsA === null || $setsB === null || ($setsA + $setsB) === 0) {
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

        $esperadoA = 1 / (1 + (10 ** (($sumaB - $sumaA) / $divisor)));
        $realA     = $setsA / ($setsA + $setsB);

        $deltaEquipoA    = $k * ($realA - $esperadoA);
        $deltaPorJugador = round($deltaEquipoA / 2, 2);

        $deltas = [];
        foreach ($equipoA as $jugador) {
            $deltas[$jugador->id] = $deltaPorJugador;
        }
        foreach ($equipoB as $jugador) {
            $deltas[$jugador->id] = -$deltaPorJugador;
        }

        return $deltas;
    }
}

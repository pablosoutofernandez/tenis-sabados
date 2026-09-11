<?php

namespace App\Services;

use App\Models\Jornada;
use App\Models\Jugador;
use Illuminate\Support\Collection;

/**
 * Reúne lo que el algoritmo de emparejamiento necesita del torneo: qué
 * parejas y partidos no conviene repetir todavía.
 */
class HistorialTenis
{
    /**
     * Parejas que han jugado juntas en las últimas N jornadas. El algoritmo
     * evita repetirlas.
     *
     * @return array<int, array{ids: array<int>, nombres: string, fecha: string}>
     */
    public function parejasRecientes(?int $jornadas = null): array
    {
        $jornadas ??= (int) config('tenis.no_repetir.parejas_ultimas_jornadas', 4);
        $vistas = [];

        foreach ($this->ultimasJornadasConPartidos($jornadas) as $jornada) {
            foreach ($jornada->partidos as $partido) {
                foreach (['a', 'b'] as $equipo) {
                    $pareja = $partido->equipo($equipo);
                    if ($pareja->count() !== 2) {
                        continue;
                    }
                    $ids  = $pareja->pluck('id')->sort()->values()->all();
                    $clave = implode('-', $ids);

                    $vistas[$clave] ??= [
                        'ids'     => $ids,
                        'nombres' => $pareja->pluck('nombre')->join(' + '),
                        'fecha'   => $jornada->fecha->toDateString(),
                    ];
                }
            }
        }

        return array_values($vistas);
    }

    /**
     * Enfrentamientos (los mismos 4 jugadores en una pista) de las últimas N jornadas.
     *
     * @return array<int, array{ids: array<int>, nombres: string, fecha: string}>
     */
    public function partidosRecientes(?int $jornadas = null): array
    {
        $jornadas ??= (int) config('tenis.no_repetir.partidos_ultimas_jornadas', 4);
        $vistos = [];

        foreach ($this->ultimasJornadasConPartidos($jornadas) as $jornada) {
            foreach ($jornada->partidos as $partido) {
                $ids   = $partido->jugadores->pluck('id')->sort()->values()->all();
                $clave = implode('-', $ids);

                $vistos[$clave] ??= [
                    'ids'     => $ids,
                    'nombres' => $partido->equipo('a')->pluck('nombre')->join(' + ')
                                 .' vs '.$partido->equipo('b')->pluck('nombre')->join(' + '),
                    'fecha'   => $jornada->fecha->toDateString(),
                ];
            }
        }

        return array_values($vistos);
    }

    /** Cuántas pistas salen hoy: entre 2 y 3 según los disponibles. */
    public function pistasPara(int $disponibles): int
    {
        $porPista = (int) config('tenis.jugadores_por_pista', 4);
        $maximo   = (int) config('tenis.pistas_max', 3);

        return min($maximo, intdiv($disponibles, $porPista));
    }

    /** @return Collection<int, Jornada> */
    private function ultimasJornadasConPartidos(int $cuantas): Collection
    {
        return Jornada::with(['partidos.jugadores', 'sinPista'])
            ->orderByDesc('fecha')
            ->limit($cuantas)
            ->get();
    }

    /**
     * Matriz cruda (por id) de cuántas veces cada jugador ha sido compañero
     * o rival de otro esta temporada. La usa el algoritmo de emparejamiento
     * para puntuar combinaciones.
     *
     * @return array{0: array, 1: array} [companeros, rivales], cada uno
     *         [jugador_id => [otro_id => veces]]
     */
    public function coincidenciasCrudas(int $anio): array
    {
        $companeros = [];
        $rivales    = [];

        $jornadas = Jornada::with('partidos.jugadores')
            ->whereYear('fecha', $anio)
            ->get();

        foreach ($jornadas as $jornada) {
            foreach ($jornada->partidos as $partido) {
                $a = $partido->equipo('a');
                $b = $partido->equipo('b');

                foreach ([[$a, $b], [$b, $a]] as [$propio, $contrario]) {
                    foreach ($propio as $jugador) {
                        foreach ($propio as $otro) {
                            if ($otro->id !== $jugador->id) {
                                $companeros[$jugador->id][$otro->id] = ($companeros[$jugador->id][$otro->id] ?? 0) + 1;
                            }
                        }
                        foreach ($contrario as $rival) {
                            $rivales[$jugador->id][$rival->id] = ($rivales[$jugador->id][$rival->id] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        return [$companeros, $rivales];
    }
}

<?php

namespace App\Services\Emparejamiento;

use App\Models\AjustesIA;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use Illuminate\Support\Carbon;

/**
 * Juega una temporada ficticia con el emparejador y mide cómo se comporta:
 * equilibrio, repeticiones y qué compañeros le tocan al líder. Sirve para
 * calibrar Pesos comparando métricas entre ajustes.
 *
 * Escribe en la base de datos por defecto, así que solo debe usarse sobre
 * una base desechable (el comando tenis:simular monta una en memoria).
 * Los niveles no se mueven durante la simulación, para que las métricas
 * dependan solo del emparejador.
 */
class Simulador
{
    /** @var callable(Jornada, array<int>): array */
    private $proponer;

    public function __construct(private Emparejador $emparejador, ?callable $proponer = null)
    {
        $this->proponer = $proponer ?? fn (Jornada $j, array $ids) => $this->emparejador->proponer($j, $ids);
    }

    /**
     * @param  array{parejas?: int, rivales?: int, equilibrio?: int, lider?: int}  $ajustes
     * @return array<string, float|int>
     */
    public function temporada(array $ajustes, int $semanas = 30, int $semilla = 1, int $plantilla = 14): array
    {
        mt_srand($semilla);

        AjustesIA::actuales()->update([
            'prioridad_no_repetir_parejas' => $ajustes['parejas'] ?? 3,
            'prioridad_no_repetir_rivales' => $ajustes['rivales'] ?? 3,
            'prioridad_equilibrio'         => $ajustes['equilibrio'] ?? 3,
            'prioridad_frenar_lider'       => $ajustes['lider'] ?? 3,
        ]);

        // Niveles de 3 a 8 en pasos de 0.5, como un grupo real.
        $niveles = [];
        for ($i = 0; $i < $plantilla; $i++) {
            $id = Jugador::create(['nombre' => "Sim{$semilla}-{$i}", 'nivel' => mt_rand(6, 16) / 2, 'activo' => true])->id;
            $niveles[$id] = Jugador::find($id)->nivel;
        }

        $m = [
            'pistas' => 0, 'dif_total' => 0.0, 'dif_max' => 0.0, 'descompensadas' => 0,
            'pareja_sem_pasada' => 0, 'pareja_ult_4' => 0, 'rival_sem_pasada' => 0,
            'lider_semanas' => 0, 'lider_con_flojo' => 0, 'lider_rango_compi' => 0.0, 'plus_lider' => 0.0,
        ];

        $parejasPorSemana = [];
        $rivalesPorSemana = [];
        $fecha = Carbon::parse('2026-02-21');

        for ($s = 0; $s < $semanas; $s++, $fecha->addWeek()) {
            $ids = array_keys($niveles);
            shuffle($ids);
            $disponibles = array_slice($ids, 0, mt_rand(8, min(13, $plantilla)));

            $jornada   = Jornada::create(['fecha' => $fecha->toDateString(), 'pistas' => 2, 'estado' => 'borrador']);
            $propuesta = ($this->proponer)($jornada, $disponibles);
            $this->emparejador->aplicar($jornada, $propuesta);

            $lider = $propuesta['calculo']['lider'] ?? null;
            $m['plus_lider'] += $propuesta['calculo']['plus_lider'] ?? 0;
            $convocados = collect($propuesta['partidos'])->flatMap(fn ($p) => [...$p['equipo_a'], ...$p['equipo_b']]);
            $ranking = $convocados->sortBy(fn ($id) => $niveles[$id])->values()->flip(); // 0 = el más flojo

            $parejas = [];
            $rivales = [];

            foreach ($propuesta['partidos'] as $p) {
                $sumaA = $niveles[$p['equipo_a'][0]] + $niveles[$p['equipo_a'][1]];
                $sumaB = $niveles[$p['equipo_b'][0]] + $niveles[$p['equipo_b'][1]];
                $dif   = abs($sumaA - $sumaB);

                $m['pistas']++;
                $m['dif_total'] += $dif;
                $m['dif_max'] = max($m['dif_max'], $dif);
                $m['descompensadas'] += $dif >= 3 ? 1 : 0;

                foreach ([$p['equipo_a'], $p['equipo_b']] as $par) {
                    $clave = Repartos::clave($par);
                    $parejas[] = $clave;
                    $m['pareja_sem_pasada'] += in_array($clave, $parejasPorSemana[$s - 1] ?? [], true) ? 1 : 0;
                    $m['pareja_ult_4'] += collect(range(1, 4))->contains(fn ($k) => in_array($clave, $parejasPorSemana[$s - $k] ?? [], true)) ? 1 : 0;

                    if ($lider !== null && in_array($lider, $par, true)) {
                        $compi = $par[0] === $lider ? $par[1] : $par[0];
                        $m['lider_semanas']++;
                        $m['lider_rango_compi'] += $ranking[$compi] / max(1, $convocados->count() - 1);
                        $m['lider_con_flojo'] += $ranking[$compi] < $convocados->count() / 4 ? 1 : 0;
                    }
                }

                foreach ($p['equipo_a'] as $x) {
                    foreach ($p['equipo_b'] as $y) {
                        $clave = Repartos::clave([$x, $y]);
                        $rivales[] = $clave;
                        $m['rival_sem_pasada'] += in_array($clave, $rivalesPorSemana[$s - 1] ?? [], true) ? 1 : 0;
                    }
                }

                $this->resultado($jornada, $p, $sumaA - $sumaB);
            }

            $parejasPorSemana[$s] = $parejas;
            $rivalesPorSemana[$s] = $rivales;
            $jornada->update(['estado' => 'publicada']);
        }

        return [
            'dif_media'         => round($m['dif_total'] / max(1, $m['pistas']), 2),
            'dif_max'           => $m['dif_max'],
            'pct_descomp'       => round(100 * $m['descompensadas'] / max(1, $m['pistas']), 1),
            'pareja_sem_pasada' => $m['pareja_sem_pasada'],
            'pareja_ult_4'      => $m['pareja_ult_4'],
            'rival_sem_pasada'  => $m['rival_sem_pasada'],
            'lider_semanas'     => $m['lider_semanas'],
            'pct_lider_flojo'   => round(100 * $m['lider_con_flojo'] / max(1, $m['lider_semanas']), 1),
            'rango_compi_lider' => round($m['lider_rango_compi'] / max(1, $m['lider_semanas']), 2),
            'plus_lider_medio'  => round($m['plus_lider'] / max(1, $semanas), 2),
        ];
    }

    /** Resultado al azar según la diferencia de nivel (3 sets, 1 punto por set). */
    private function resultado(Jornada $jornada, array $p, float $diferencia): void
    {
        // Con 4 puntos de diferencia de suma, el favorito gana ~91% de los sets.
        $probA = 1 / (1 + 10 ** (-$diferencia / 4.0));
        $setsA = 0;
        for ($i = 0; $i < 3; $i++) {
            $setsA += mt_rand() / mt_getrandmax() < $probA ? 1 : 0;
        }

        $partido = Partido::where('jornada_id', $jornada->id)->where('pista', $p['pista'])->first();
        $partido->update(['sets_a' => $setsA, 'sets_b' => 3 - $setsA]);

        foreach (['a' => $setsA, 'b' => 3 - $setsA] as $equipo => $puntos) {
            foreach ($p['equipo_'.$equipo] as $id) {
                $partido->jugadores()->updateExistingPivot($id, ['puntos' => $puntos]);
            }
        }
    }
}

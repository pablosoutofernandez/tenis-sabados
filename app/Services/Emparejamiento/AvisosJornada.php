<?php

namespace App\Services\Emparejamiento;

use App\Models\AjustesIA;
use App\Models\Jornada;
use App\Services\HistorialTenis;

/**
 * Lo que se le señala al organizador al revisar una propuesta: repeticiones
 * recientes y pistas descompensadas. No decide nada (eso es ModeloCoste);
 * solo avisa de lo que el mejor reparto posible no pudo evitar.
 *
 * Solo mira jornadas de la misma temporada y anteriores a la que se monta,
 * así un borrador previo de esa misma jornada no cuenta como repetición.
 */
class AvisosJornada
{
    public function __construct(private HistorialTenis $historial)
    {
    }

    /** @return array<int, string> */
    public function para(Jornada $jornada, array $propuesta): array
    {
        $ajustes  = AjustesIA::actuales();
        $ventanas = $this->ventanas($ajustes, $jornada);

        $porClave = fn (array $lista) => collect($lista)->keyBy(fn ($p) => implode('-', $p['ids']));

        $parejas  = $porClave($this->historial->parejasRecientes($ventanas['parejas'], $jornada->fecha));
        $rivales  = $porClave($this->historial->rivalesRecientes($ventanas['rivales'], $jornada->fecha));
        $partidos = $porClave($this->historial->partidosRecientes($ventanas['partidos'], $jornada->fecha));

        $avisos = [];

        foreach ($propuesta['partidos'] ?? [] as $datos) {
            if ($repetido = $partidos->get(Repartos::clave([...$datos['equipo_a'], ...$datos['equipo_b']]))) {
                $avisos[] = 'Este cuarteto ya coincidió el '.$repetido['fecha'].': '.$repetido['nombres'].'.';
            }

            foreach (['equipo_a', 'equipo_b'] as $equipo) {
                if ($repetida = $parejas->get(Repartos::clave($datos[$equipo]))) {
                    $avisos[] = 'La pareja '.$repetida['nombres'].' ya jugó junta el '.$repetida['fecha'].'.';
                }
            }

            // Los cruces repetidos van en una sola línea por pista: hay 4
            // por partido y en lista suelta inundarían el aviso.
            $cruces = [];
            foreach ($datos['equipo_a'] as $uno) {
                foreach ($datos['equipo_b'] as $otro) {
                    if ($repetido = $rivales->get(Repartos::clave([$uno, $otro]))) {
                        $cruces[] = $repetido['nombres'].' (el '.$repetido['fecha'].')';
                    }
                }
            }

            if ($cruces) {
                $avisos[] = 'Pista '.($datos['pista'] ?? '?').': cruces que ya se dieron hace poco: '.implode(', ', $cruces).'.';
            }
        }

        return array_values(array_unique([...$avisos, ...$this->desequilibrios($jornada, $propuesta, $ajustes)]));
    }

    /**
     * Pistas cuya diferencia de suma de nivel efectivo (el mismo número que
     * usó la búsqueda) llega al umbral.
     *
     * @return array<int, string>
     */
    private function desequilibrios(Jornada $jornada, array $propuesta, AjustesIA $ajustes): array
    {
        $niveles = $propuesta['calculo']['niveles'] ?? null;

        if ($niveles === null) {
            $ids = collect($propuesta['partidos'] ?? [])
                ->flatMap(fn ($p) => [...$p['equipo_a'], ...$p['equipo_b']])
                ->map(fn ($id) => (int) $id)->unique()->values()->all();

            $niveles = NivelEfectivo::calcular($ids, (int) $jornada->fecha->year, Pesos::desde($ajustes))['niveles'];
        }

        $umbral = (float) config('tenis.aviso_desequilibrio_nivel', 3) * match ($ajustes->prioridad_equilibrio) {
            1 => 2.0,
            2 => 1.5,
            4 => 0.7,
            5 => 0.5,
            default => 1.0,
        };

        $avisos = [];

        foreach ($propuesta['partidos'] ?? [] as $datos) {
            $sumaA = round(collect($datos['equipo_a'])->sum(fn ($id) => $niveles[$id] ?? 0), 1);
            $sumaB = round(collect($datos['equipo_b'])->sum(fn ($id) => $niveles[$id] ?? 0), 1);

            if (abs($sumaA - $sumaB) >= $umbral) {
                $avisos[] = 'Pista '.($datos['pista'] ?? '?').": las parejas están descompensadas ({$sumaA} vs {$sumaB}).";
            }
        }

        return $avisos;
    }

    /**
     * Cuántas jornadas atrás mira cada aviso. Solo afecta a los avisos, no
     * a la búsqueda (que pesa cada repetición según su recencia real). La
     * base de config/tenis.php se estira o encoge con la prioridad; en el
     * máximo se repasa toda la temporada.
     *
     * @return array{parejas: int, rivales: int, partidos: int}
     */
    private function ventanas(AjustesIA $ajustes, Jornada $jornada): array
    {
        $parejas = $ajustes->prioridad_no_repetir_parejas;
        $rivales = $ajustes->prioridad_no_repetir_rivales;
        $toda    = max(1, $this->historial->jornadasJugadasAntesDe($jornada->fecha));

        $ventana = fn (int $base, int $prioridad) => $prioridad >= 5 ? $toda : max(1, (int) round($base * match ($prioridad) {
            1 => 0.5,
            2 => 0.75,
            4 => 1.5,
            default => 1.0,
        }));

        return [
            'parejas'  => $ventana((int) config('tenis.no_repetir.parejas_ultimas_jornadas', 4), $parejas),
            'rivales'  => $ventana((int) config('tenis.no_repetir.rivales_ultimas_jornadas', 2), $rivales),
            'partidos' => $ventana((int) config('tenis.no_repetir.partidos_ultimas_jornadas', 4), max($parejas, $rivales)),
        ];
    }
}

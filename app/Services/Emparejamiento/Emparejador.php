<?php

namespace App\Services\Emparejamiento;

use App\Models\AjustesIA;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use App\Services\HistorialTenis;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monta los partidos del sábado.
 *
 *   proponer() -> calcula el mejor reparto y lo redacta
 *   aplicar()  -> lo guarda como partidos de la jornada
 *
 * No hay ninguna IA: es una búsqueda exhaustiva. Se recorren todos los
 * repartos posibles de los convocados en pistas de 4, cada uno se puntúa
 * con ModeloCoste (equilibrio de nivel + repeticiones de parejas y rivales,
 * pesando más lo reciente) y gana el más barato. Si varios empatan, se
 * elige al azar entre ellos para no caer siempre en la misma solución.
 *
 * Las escalas de todo esto viven en Pesos; los avisos para el organizador,
 * en AvisosJornada.
 */
class Emparejador
{
    /** Cuántos de los mejores repartos se guardan para el detalle técnico. */
    private const ALTERNATIVAS = 5;

    public function __construct(private HistorialTenis $historial)
    {
    }

    /**
     * @param  array<int>  $jugadorIds  Disponibles ese sábado.
     * @param  array<string>  $excluirFirmas  Repartos ya enseñados (ver Repartos::firma)
     *         que "Probar otro emparejamiento" no quiere volver a ver.
     * @return array{no_juegan: array<int>, partidos: array<int, array>, explicacion: string, coste: float, firma: string}
     */
    public function proponer(Jornada $jornada, array $jugadorIds, array $excluirFirmas = []): array
    {
        $jugadorIds = array_map('intval', $jugadorIds);
        $pistas     = $this->historial->pistasPara(count($jugadorIds));

        if ($pistas < 1) {
            throw new RuntimeException('Hacen falta al menos 4 jugadores disponibles para montar una pista.');
        }

        $anio  = (int) $jornada->fecha->year;
        $pesos = Pesos::desde(AjustesIA::actuales());

        [$convocados, $noJuegan] = $this->convocar($jugadorIds, $pistas, $anio);

        $efectivo = NivelEfectivo::calcular($convocados, $anio, $pesos);
        [$companeros, $rivales] = $this->historial->coincidenciasPorDistancia($anio, $jornada->fecha);

        $modelo = new ModeloCoste($pesos, $efectivo['niveles'], $companeros, $rivales);

        $busqueda = $this->buscar($convocados, $modelo, array_flip($excluirFirmas));

        if ($busqueda['elegido'] === null) {
            // Todo excluido tras pedir "otro" muchas veces: mejor repetida que nada.
            $busqueda = $this->buscar($convocados, $modelo, []);
        }

        $elegido = $busqueda['elegido'];

        $propuesta = [
            'no_juegan'   => $noJuegan,
            'partidos'    => $this->redactar($elegido['pistas'], $efectivo, $companeros, $rivales),
            'explicacion' => $this->explicacion(count($elegido['pistas']), $efectivo),
            'coste'       => $elegido['coste'],
            'firma'       => Repartos::firma($elegido['claves']),
            'calculo'     => $this->calculo($pesos, $efectivo, $modelo, $busqueda, $noJuegan),
        ];

        $this->validar($propuesta, $jugadorIds, $pistas);

        return $propuesta;
    }

    /** Guarda la propuesta como partidos reales de la jornada. */
    public function aplicar(Jornada $jornada, array $propuesta): void
    {
        DB::transaction(function () use ($jornada, $propuesta) {
            $jornada->limpiarPartidos();

            foreach ($propuesta['no_juegan'] ?? [] as $jugadorId) {
                DB::table('jornada_jugador')
                    ->where('jornada_id', $jornada->id)
                    ->where('jugador_id', $jugadorId)
                    ->update(['fuera' => true]);
            }

            foreach ($propuesta['partidos'] as $indice => $datos) {
                $partido = Partido::create([
                    'jornada_id' => $jornada->id,
                    'pista'      => $datos['pista'] ?? $indice + 1,
                    'motivo_ia'  => $datos['motivo'] ?? null,
                ]);

                $filas = [];
                foreach (['a', 'b'] as $equipo) {
                    foreach ($datos['equipo_'.$equipo] as $jugadorId) {
                        $filas[$jugadorId] = ['equipo' => $equipo, 'puntos' => 0];
                    }
                }
                $partido->jugadores()->attach($filas);
            }

            $jornada->update([
                'pistas'         => count($propuesta['partidos']),
                'explicacion_ia' => $propuesta['explicacion'] ?? null,
                'calculo'        => $propuesta['calculo'] ?? null,
            ]);
        });
    }

    /**
     * Quién se queda sin jugar si sobran: juega antes quien menos partidos
     * lleva esta temporada; a igualdad, al azar, para no dejar fuera
     * siempre a los mismos.
     *
     * @return array{0: array<int>, 1: array<int>} [convocados, no_juegan]
     */
    private function convocar(array $jugadorIds, int $pistas, int $anio): array
    {
        $enJuego = $pistas * (int) config('tenis.jugadores_por_pista', 4);
        $jugados = Jugador::partidosJugadosPorJugador($anio);

        $ordenados = collect($jugadorIds)
            ->shuffle()
            ->sortBy(fn ($id) => (int) ($jugados[$id] ?? 0))
            ->values();

        return [$ordenados->slice(0, $enJuego)->values()->all(), $ordenados->slice($enJuego)->values()->all()];
    }

    /**
     * Recorre los repartos y devuelve el más barato no excluido (null si lo
     * están todos), elegido al azar entre los que empatan, junto con datos
     * de la búsqueda para el detalle técnico.
     *
     * @return array{elegido: ?array, evaluados: int, excluidos: int, empatados: int, alternativas: array}
     */
    private function buscar(array $convocados, ModeloCoste $modelo, array $excluidas): array
    {
        $porPista     = (int) config('tenis.jugadores_por_pista', 4);
        $mejorCoste   = INF;
        $candidatos   = [];
        $alternativas = [];
        $evaluados    = 0;
        $excluidos    = 0;

        foreach (Repartos::posibles($convocados, $porPista) as $particion) {
            $claves = array_map([Repartos::class, 'clave'], $particion);

            if (isset($excluidas[Repartos::firma($claves)])) {
                $excluidos++;

                continue;
            }

            $evaluados++;

            $coste  = 0.0;
            $pistas = [];

            foreach ($particion as $i => $cuarteto) {
                [$equipoA, $equipoB, $costePista] = $modelo->mejorSplit($cuarteto, $claves[$i]);

                $coste   += $costePista;
                $pistas[] = ['pista' => $i + 1, 'equipo_a' => $equipoA, 'equipo_b' => $equipoB];
            }

            $this->apuntarAlternativa($alternativas, $coste, $pistas);

            if ($coste > $mejorCoste + Pesos::TOLERANCIA_EMPATE) {
                continue;
            }

            if ($coste < $mejorCoste) {
                $mejorCoste = $coste;
                $candidatos = array_values(array_filter(
                    $candidatos,
                    fn ($c) => $c['coste'] <= $mejorCoste + Pesos::TOLERANCIA_EMPATE,
                ));
            }

            $candidatos[] = ['coste' => $coste, 'pistas' => $pistas, 'claves' => $claves];
        }

        return [
            'elegido'      => $candidatos === [] ? null : $candidatos[array_rand($candidatos)],
            'evaluados'    => $evaluados,
            'excluidos'    => $excluidos,
            'empatados'    => count($candidatos),
            'alternativas' => $alternativas,
        ];
    }

    /** Mantiene los ALTERNATIVAS repartos más baratos vistos, ordenados por coste. */
    private function apuntarAlternativa(array &$alternativas, float $coste, array $pistas): void
    {
        if (count($alternativas) >= self::ALTERNATIVAS && $coste >= end($alternativas)['coste']) {
            return;
        }

        $alternativas[] = ['coste' => $coste, 'pistas' => $pistas];
        usort($alternativas, fn ($x, $y) => $x['coste'] <=> $y['coste']);
        $alternativas = array_slice($alternativas, 0, self::ALTERNATIVAS);
    }

    /**
     * Todo lo necesario para auditar y calibrar una propuesta: los pesos en
     * vigor, el nivel efectivo de cada uno, cómo se sumó el coste de cada
     * pista y qué otras combinaciones quedaron cerca.
     */
    private function calculo(Pesos $pesos, array $efectivo, ModeloCoste $modelo, array $busqueda, array $noJuegan): array
    {
        $elegido = $busqueda['elegido'];

        return [
            'pesos' => [
                'pareja'            => $pesos->pareja,
                'rival'             => $pesos->rival,
                'equilibrio'        => $pesos->equilibrio,
                'decay_pareja'      => Pesos::DECAY_PAREJA,
                'suelo_pareja'      => Pesos::SUELO_PAREJA,
                'decay_rival'       => $pesos->decayRival,
                'plus_lider_max'    => $pesos->plusLider,
                'tolerancia_empate' => Pesos::TOLERANCIA_EMPATE,
            ],
            'lider'      => $efectivo['lider'],
            'plus_lider' => $efectivo['plus'],
            'niveles'    => $efectivo['niveles'],
            'no_juegan'  => $noJuegan,
            'busqueda'   => [
                'evaluados' => $busqueda['evaluados'],
                'excluidos' => $busqueda['excluidos'],
                'empatados' => $busqueda['empatados'],
            ],
            'coste_total' => $elegido['coste'],
            'pistas'      => array_map(
                fn ($p) => ['pista' => $p['pista']] + $modelo->desglose($p['equipo_a'], $p['equipo_b']),
                $elegido['pistas'],
            ),
            'alternativas' => $busqueda['alternativas'],
        ];
    }

    // ── Redacción (texto, no decisiones) ────────────────────────────────────

    /**
     * Añade a cada pista un motivo legible. Usa el mismo nivel efectivo y el
     * mismo historial que la búsqueda, así lo que se lee cuadra con lo que
     * de verdad se comparó.
     */
    private function redactar(array $pistas, array $efectivo, array $companeros, array $rivales): array
    {
        $niveles = $efectivo['niveles'];

        return array_map(function (array $p) use ($niveles, $companeros, $rivales) {
            [$a1, $a2] = $p['equipo_a'];
            [$b1, $b2] = $p['equipo_b'];

            $sumaA      = round($niveles[$a1] + $niveles[$a2], 1);
            $sumaB      = round($niveles[$b1] + $niveles[$b2], 1);
            $diferencia = round(abs($sumaA - $sumaB), 1);

            $frase = "Suma de nivel {$sumaA} vs {$sumaB}".match (true) {
                $diferencia <= 0.5 => ', muy igualado.',
                $diferencia <= 2.0 => ', razonablemente equilibrado.',
                default            => ", diferencia de {$diferencia}.",
            };

            if (empty($companeros[$a1][$a2]) && empty($companeros[$b1][$b2])) {
                $frase .= ' Las dos parejas son inéditas esta temporada.';
            }

            $crucesNuevos = collect($p['equipo_a'])->every(
                fn ($x) => collect($p['equipo_b'])->every(fn ($y) => empty($rivales[$x][$y])),
            );

            if ($crucesNuevos) {
                $frase .= ' Ninguno de los cuatro se había enfrentado antes esta temporada.';
            }

            $p['motivo'] = $frase;

            return $p;
        }, $pistas);
    }

    private function explicacion(int $pistas, array $efectivo): string
    {
        $texto = "Repartidos en {$pistas} pistas buscando el mayor equilibrio de nivel con las menos"
            .' repeticiones posibles (parejas y rivales, pesando más lo más reciente).';

        if ($efectivo['lider'] !== null) {
            $nombre = Jugador::find($efectivo['lider'])?->nombre;
            $texto .= " Al líder de hoy ({$nombre}) se le ha contado +{$efectivo['plus']} de nivel.";
        }

        return $texto;
    }

    // ── Validación estructural ─────────────────────────────────────────────

    /**
     * Red de seguridad barata: por construcción la propuesta siempre
     * debería ser válida.
     *
     * @param  array<int>  $jugadorIds
     */
    public function validar(array $propuesta, array $jugadorIds, int $pistas): void
    {
        $partidos = $propuesta['partidos'] ?? null;

        if (! is_array($partidos) || count($partidos) !== $pistas) {
            throw new RuntimeException('Se calcularon '.(is_array($partidos) ? count($partidos) : 0)." partidos y esperábamos {$pistas}.");
        }

        $usados = [];

        foreach ($partidos as $partido) {
            foreach (['equipo_a', 'equipo_b'] as $equipo) {
                if (! isset($partido[$equipo]) || count($partido[$equipo]) !== 2) {
                    throw new RuntimeException('Cada pareja debe tener exactamente 2 jugadores.');
                }
                foreach ($partido[$equipo] as $id) {
                    $usados[] = (int) $id;
                }
            }
        }

        foreach ($propuesta['no_juegan'] ?? [] as $id) {
            $usados[] = (int) $id;
        }

        if (count($usados) !== count(array_unique($usados))) {
            throw new RuntimeException('Hay jugadores repetidos en la propuesta.');
        }

        if (array_diff($usados, $jugadorIds)) {
            throw new RuntimeException('Se incluyó a alguien que no estaba disponible.');
        }

        if ($faltan = array_diff($jugadorIds, $usados)) {
            throw new RuntimeException('Se han quedado '.count($faltan).' disponible(s) sin pista.');
        }
    }
}

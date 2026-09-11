<?php

namespace App\Services;

use App\Models\AjustesIA;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monta los partidos del sábado.
 *
 *   proponer()  -> calcula la asignación y la redacta
 *   avisos()    -> señala repeticiones o desequilibrios que quedaron
 *   aplicar()   -> la guarda como partidos de la jornada
 *
 * QUIÉN JUEGA CON QUIÉN NO LO DECIDE NINGUNA IA. Es un problema de
 * optimización combinatoria (repartir N jugadores en parejas minimizando
 * repeticiones y maximizando equilibrio), y pedírselo a un modelo de
 * lenguaje en una sola pasada de texto daba justo los fallos que cabía
 * esperar: se quedaba con la combinación más "obvia" en vez de explorar
 * alternativas, y llegaba a decir que una repetición era inevitable cuando
 * no lo era. Así que la asignación la calcula un algoritmo determinista
 * aquí mismo (calcularAsignacion, más abajo): prueba muchas particiones al
 * azar, puntúa cada una según las mismas reglas de siempre (no repetir,
 * equilibrio, nivel_efectivo del líder) y se queda con la mejor — y si hay
 * varias igual de buenas, elige entre ellas al azar, para no repetir
 * siempre la misma "solución obvia".
 *
 * El nivel de cada jugador ya no lo revisa una IA tampoco: se ajusta solo
 * tras cada resultado con un sistema tipo Elo (ver App\Services\EloNiveles).
 */
class EmparejadorIA
{
    public function __construct(private HistorialTenis $historial)
    {
    }

    /**
     * @param  array<int>  $jugadorIds  Disponibles ese sábado.
     * @return array{no_juegan: array<int>, partidos: array<int, array>, explicacion: string}
     */
    public function proponer(Jornada $jornada, array $jugadorIds): array
    {
        $pistas = $this->historial->pistasPara(count($jugadorIds));

        if ($pistas < 1) {
            throw new RuntimeException('Hacen falta al menos 4 jugadores disponibles para montar una pista.');
        }

        $ajustes = AjustesIA::actuales();
        $anio    = (int) $jornada->fecha->year;

        $asignacion = $this->calcularAsignacion($jugadorIds, $pistas, $anio, $ajustes);
        $propuesta  = $this->redactar($asignacion, $anio, $ajustes);

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
            ]);
        });
    }

    /**
     * Comprueba si la propuesta repite algo que no tocaba repetir, o deja
     * una pista descompensada. El algoritmo ya intenta evitar ambas cosas,
     * así que esto debería saltar muy pocas veces — cuando salta, es que de
     * verdad no había alternativa mejor con los disponibles de hoy.
     *
     * @return array<int, string>
     */
    public function avisos(array $propuesta, ?int $anio = null): array
    {
        $anio    ??= (int) now()->year;
        $ajustes   = AjustesIA::actuales();

        [$ventanaParejas, $ventanaPartidos] = $this->ventanas($ajustes);

        $parejasVetadas  = collect($this->historial->parejasRecientes($ventanaParejas))
            ->keyBy(fn ($p) => implode('-', $p['ids']));
        $partidosVetados = collect($this->historial->partidosRecientes($ventanaPartidos))
            ->keyBy(fn ($p) => implode('-', $p['ids']));

        $avisos = [];

        foreach ($propuesta['partidos'] ?? [] as $datos) {
            $cuarteto = collect([...$datos['equipo_a'], ...$datos['equipo_b']])
                ->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($repetido = $partidosVetados->get(implode('-', $cuarteto))) {
                $avisos[] = 'Este cuarteto ya coincidió el '.$repetido['fecha'].': '.$repetido['nombres'].'.';
            }

            foreach (['equipo_a', 'equipo_b'] as $equipo) {
                $pareja = collect($datos[$equipo])->map(fn ($id) => (int) $id)->sort()->values()->all();

                if ($repetida = $parejasVetadas->get(implode('-', $pareja))) {
                    $avisos[] = 'La pareja '.$repetida['nombres'].' ya jugó junta el '.$repetida['fecha'].'.';
                }
            }
        }

        return array_values(array_unique(array_merge($avisos, $this->avisosDesequilibrio($propuesta, $anio, $ajustes))));
    }

    /**
     * Comprobación aparte: compara la suma de nivel_efectivo de las dos
     * parejas de cada pista con el umbral configurado.
     *
     * @return array<int, string>
     */
    private function avisosDesequilibrio(array $propuesta, int $anio, AjustesIA $ajustes): array
    {
        $umbral = $this->umbralDesequilibrio($ajustes);

        $ids = collect($propuesta['partidos'] ?? [])
            ->flatMap(fn ($p) => [...($p['equipo_a'] ?? []), ...($p['equipo_b'] ?? [])])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $efectivos = $this->nivelesEfectivos($ids, $anio, $ajustes);

        $avisos = [];

        foreach ($propuesta['partidos'] ?? [] as $datos) {
            $sumaA = collect($datos['equipo_a'] ?? [])->sum(fn ($id) => $efectivos[(int) $id] ?? 0);
            $sumaB = collect($datos['equipo_b'] ?? [])->sum(fn ($id) => $efectivos[(int) $id] ?? 0);

            if (abs($sumaA - $sumaB) >= $umbral) {
                $avisos[] = 'Pista '.($datos['pista'] ?? '?').": las parejas están descompensadas ({$sumaA} vs {$sumaB}).";
            }
        }

        return $avisos;
    }

    // ── El algoritmo: quién juega con quién ─────────────────────────────────

    /**
     * Prueba muchas particiones al azar de los disponibles en pistas de 4,
     * puntúa cada una (repeticiones, equilibrio) y se queda con la mejor.
     * Entre las que empatan de verdad, elige al azar — así no acaba siempre
     * con la misma combinación "obvia" cuando hay varias igual de válidas.
     *
     * @param  array<int>  $jugadorIds
     * @return array{partidos: array<int, array{pista: int, equipo_a: array<int>, equipo_b: array<int>}>, no_juegan: array<int>}
     */
    private function calcularAsignacion(array $jugadorIds, int $pistas, int $anio, AjustesIA $ajustes): array
    {
        $porPista = (int) config('tenis.jugadores_por_pista', 4);
        $enJuego  = $pistas * $porPista;

        // Quién se queda sin jugar si sobran: prioridad a jugar para quien
        // menos partidos lleva esta temporada; a igualdad, al azar (para no
        // dejar siempre fuera a los mismos si hay un empate persistente).
        $jugados = Jugador::partidosJugadosPorJugador($anio);
        $ordenados = collect($jugadorIds)
            ->shuffle()
            ->sortBy(fn ($id) => (int) ($jugados[$id] ?? 0))
            ->values();

        $convocados = $ordenados->slice(0, $enJuego)->values()->all();
        $noJuegan   = $ordenados->slice($enJuego)->values()->all();

        [$ventanaParejas, $ventanaPartidos] = $this->ventanas($ajustes);

        $parejasVetadas  = collect($this->historial->parejasRecientes($ventanaParejas))
            ->keyBy(fn ($p) => implode('-', $p['ids']))->all();
        $partidosVetados = collect($this->historial->partidosRecientes($ventanaPartidos))
            ->keyBy(fn ($p) => implode('-', $p['ids']))->all();

        [$companeros, $rivales] = $this->historial->coincidenciasCrudas($anio);
        $niveles = $this->nivelesEfectivos($convocados, $anio, $ajustes);

        $pesoPareja     = $this->pesoPareja($ajustes);
        $pesoRival      = $this->pesoRival($ajustes);
        $pesoEquilibrio = $this->pesoEquilibrio($ajustes);

        $intentos   = (int) min(3000, max(400, count($convocados) * 150));
        $resultados = [];

        for ($i = 0; $i < $intentos; $i++) {
            $barajado  = collect($convocados)->shuffle()->values()->all();
            $particion = array_chunk($barajado, $porPista);

            $costeTotal = 0.0;
            $pistasCalculadas = [];

            foreach ($particion as $indicePista => $cuarteto) {
                [$equipoA, $equipoB, $coste] = $this->mejorSplitDeCuarteto(
                    $cuarteto, $niveles, $parejasVetadas, $partidosVetados, $companeros, $rivales,
                    $pesoPareja, $pesoRival, $pesoEquilibrio,
                );

                $costeTotal += $coste;
                $pistasCalculadas[] = [
                    'pista'    => $indicePista + 1,
                    'equipo_a' => $equipoA,
                    'equipo_b' => $equipoB,
                ];
            }

            $resultados[] = ['coste' => $costeTotal, 'pistas' => $pistasCalculadas];
        }

        $mejorCoste = collect($resultados)->min('coste');
        $candidatos = collect($resultados)->filter(fn ($r) => $r['coste'] <= $mejorCoste + 0.5)->values()->all();
        $elegido    = $candidatos[array_rand($candidatos)];

        return ['partidos' => $elegido['pistas'], 'no_juegan' => $noJuegan];
    }

    /** De los 3 repartos posibles de 4 en 2v2, el de menor coste. */
    private function mejorSplitDeCuarteto(
        array $cuarteto,
        array $niveles,
        array $parejasVetadas,
        array $partidosVetados,
        array $companeros,
        array $rivales,
        float $pesoPareja,
        float $pesoRival,
        float $pesoEquilibrio,
    ): array {
        [$a, $b, $c, $d] = $cuarteto;

        $opciones = [
            [[$a, $b], [$c, $d]],
            [[$a, $c], [$b, $d]],
            [[$a, $d], [$b, $c]],
        ];

        $mejor      = null;
        $mejorCoste = INF;

        foreach ($opciones as [$equipoA, $equipoB]) {
            $coste = $this->costePista(
                $cuarteto, $equipoA, $equipoB, $niveles, $parejasVetadas, $partidosVetados,
                $companeros, $rivales, $pesoPareja, $pesoRival, $pesoEquilibrio,
            );

            if ($coste < $mejorCoste) {
                $mejorCoste = $coste;
                $mejor = [$equipoA, $equipoB];
            }
        }

        return [$mejor[0], $mejor[1], $mejorCoste];
    }

    /**
     * Coste de una pista concreta: alto si repite algo vetado, creciente
     * según cuántas veces esas combinaciones ya se han dado esta temporada
     * (con $pesoPareja/$pesoRival, no una constante fija), según
     * $pesoEquilibrio por cada punto de diferencia de nivel_efectivo —
     * al cuadrado, así que crece rápido; sin umbral que la deje a cero por
     * debajo de un mínimo, porque eso hacía que la variedad ganara siempre
     * en cualquier diferencia moderada sin importar lo bajo que se pusiera
     * su peso, dado que en esa franja el equilibrio no competía con nada —
     * y un matiz fijo de peso pequeño que evita juntar al mejor nivel con
     * el más flojo como compañeros aunque la suma cuadre.
     */
    private function costePista(
        array $cuarteto,
        array $equipoA,
        array $equipoB,
        array $niveles,
        array $parejasVetadas,
        array $partidosVetados,
        array $companeros,
        array $rivales,
        float $pesoPareja,
        float $pesoRival,
        float $pesoEquilibrio,
    ): float {
        $coste = 0.0;

        $claveCuarteto = collect($cuarteto)->sort()->values()->implode('-');
        if (isset($partidosVetados[$claveCuarteto])) {
            $coste += 60.0;
        }

        foreach ([$equipoA, $equipoB] as $pareja) {
            $clave = collect($pareja)->sort()->values()->implode('-');
            if (isset($parejasVetadas[$clave])) {
                $coste += 40.0;
            }
        }

        $coste += $pesoPareja * (int) ($companeros[$equipoA[0]][$equipoA[1]] ?? 0);
        $coste += $pesoPareja * (int) ($companeros[$equipoB[0]][$equipoB[1]] ?? 0);

        foreach ($equipoA as $x) {
            foreach ($equipoB as $y) {
                $coste += $pesoRival * (int) ($rivales[$x][$y] ?? 0);
            }
        }

        $sumaA      = $niveles[$equipoA[0]] + $niveles[$equipoA[1]];
        $sumaB      = $niveles[$equipoB[0]] + $niveles[$equipoB[1]];
        $diferencia = abs($sumaA - $sumaB);
        $coste += $pesoEquilibrio * ($diferencia ** 2);

        // Matiz fijo (config/tenis.php -> objetivos): aunque la suma cuadre,
        // evita juntar al mejor nivel con el más flojo como compañeros si
        // hay alternativa. Peso pequeño y fijo, no ligado a ningún slider.
        foreach ([$equipoA, $equipoB] as $pareja) {
            $brecha = abs($niveles[$pareja[0]] - $niveles[$pareja[1]]);
            $coste += 0.3 * (max(0.0, $brecha - 2.0) ** 2);
        }

        return $coste;
    }

    // ── Redacción (texto, no decisiones) ────────────────────────────────────

    /**
     * Convierte la asignación ya calculada en la misma forma que antes
     * devolvía la IA, con un motivo por pista y una explicación general.
     * Usa nivel_efectivo (con el plus del líder ya sumado), el mismo número
     * que se usó para decidir — así lo que se ve en pantalla coincide con
     * lo que realmente se comparó al montar la jornada.
     */
    private function redactar(array $asignacion, int $anio, AjustesIA $ajustes): array
    {
        // Solo los que juegan: son los mismos convocados que se usaron para
        // decidir la asignación, así el líder que se calcula aquí es
        // exactamente el mismo que decidió la búsqueda (los "no_juegan" no
        // deben poder alterar quién cuenta como líder de hoy).
        $idsQueJuegan = collect($asignacion['partidos'])
            ->flatMap(fn ($p) => [...$p['equipo_a'], ...$p['equipo_b']])
            ->unique()->values()->all();

        $niveles = $this->nivelesEfectivos($idsQueJuegan, $anio, $ajustes);
        [$companeros] = $this->historial->coincidenciasCrudas($anio);

        $partidos = collect($asignacion['partidos'])->map(function (array $p) use ($niveles, $companeros) {
            $sumaA = round((float) ($niveles[$p['equipo_a'][0]] ?? 0) + (float) ($niveles[$p['equipo_a'][1]] ?? 0), 1);
            $sumaB = round((float) ($niveles[$p['equipo_b'][0]] ?? 0) + (float) ($niveles[$p['equipo_b'][1]] ?? 0), 1);
            $diferencia = round(abs($sumaA - $sumaB), 1);

            $frase = "Suma de nivel {$sumaA} vs {$sumaB}";
            $frase .= match (true) {
                $diferencia <= 0.5 => ', muy igualado.',
                $diferencia <= 2.0 => ', razonablemente equilibrado.',
                default => ", diferencia de {$diferencia}.",
            };

            $inedita = (int) ($companeros[$p['equipo_a'][0]][$p['equipo_a'][1]] ?? 0) === 0
                && (int) ($companeros[$p['equipo_b'][0]][$p['equipo_b'][1]] ?? 0) === 0;

            if ($inedita) {
                $frase .= ' Las dos parejas son inéditas esta temporada.';
            }

            $p['motivo'] = $frase;

            return $p;
        })->all();

        $explicacion = 'Repartidos '.count($partidos).' pistas priorizando variedad (parejas y'
            .' cruces poco repetidos esta temporada) y equilibrio de nivel dentro de cada pista.';

        return [
            'no_juegan'   => $asignacion['no_juegan'],
            'partidos'    => $partidos,
            'explicacion' => $explicacion,
        ];
    }

    // ── Prioridades del organizador (Ajustes IA) ────────────────────────────

    /**
     * La ventana base de "no repetir" (config/tenis.php) se ensancha o se
     * estrecha según la prioridad que le des a evitar repeticiones. En el
     * nivel máximo no es "mirar un poco más atrás": es repasar toda la
     * temporada jugada hasta ahora, así que la ventana cubre todas las
     * jornadas que existan.
     *
     * @return array{0: int, 1: int} [ventana parejas, ventana partidos]
     */
    private function ventanas(AjustesIA $ajustes): array
    {
        $parejas  = (int) config('tenis.no_repetir.parejas_ultimas_jornadas', 4);
        $partidos = (int) config('tenis.no_repetir.partidos_ultimas_jornadas', 4);

        if ($ajustes->prioridad_no_repetir >= 5) {
            $todas = max(1, Jornada::count());

            return [$todas, $todas];
        }

        $factor = match ($ajustes->prioridad_no_repetir) {
            1 => 0.5,
            2 => 0.75,
            4 => 1.5,
            default => 1.0,
        };

        return [
            max(1, (int) round($parejas * $factor)),
            max(1, (int) round($partidos * $factor)),
        ];
    }

    /** A más prioridad de equilibrio, más bajo el umbral que dispara el aviso. */
    private function umbralDesequilibrio(AjustesIA $ajustes): float
    {
        $base = (float) config('tenis.aviso_desequilibrio_nivel', 3);

        $factor = match ($ajustes->prioridad_equilibrio) {
            1 => 2.0,
            2 => 1.5,
            4 => 0.7,
            5 => 0.5,
            default => 1.0,
        };

        return $base * $factor;
    }

    /**
     * Cuánto pesa en el coste que dos jugadores ya hayan sido PAREJA esta
     * temporada. Esto es lo que de verdad evita que el algoritmo se quede
     * atascado repitiendo siempre la combinación más equilibrada: avanzada
     * la temporada, cuando todo el mundo ha coincidido ya un par de veces
     * con todo el mundo, subir esto hace que hasta una diferencia pequeña
     * de coincidencias pese más que el equilibrio, y fuerce variar.
     */
    private function pesoPareja(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_no_repetir) {
            1 => 0.3,
            2 => 1.0,
            4 => 6.0,
            5 => 15.0,
            default => 2.0,
        };
    }

    /** Igual que pesoPareja(), pero para haber sido solo rivales (pesa menos). */
    private function pesoRival(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_no_repetir) {
            1 => 0.1,
            2 => 0.3,
            4 => 2.0,
            5 => 5.0,
            default => 0.7,
        };
    }

    /**
     * Cuánto pesa, en el coste, cada punto de diferencia de nivel_efectivo
     * que pase del umbral. A más peso, más se sacrifica variedad con tal
     * de cuadrar las sumas.
     */
    private function pesoEquilibrio(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_equilibrio) {
            1 => 0.2,
            2 => 0.5,
            4 => 2.5,
            5 => 6.0,
            default => 1.0,
        };
    }

    /**
     * Nivel "para repartir" de cada disponible: igual al real, salvo un
     * plus fijo para quien va primero en puntos entre los disponibles de
     * hoy. En el hándicap mínimo no hay plus y esto es idéntico al real.
     *
     * @param  array<int>  $jugadorIds
     * @return array<int, float>  [jugador_id => nivel_efectivo]
     */
    private function nivelesEfectivos(array $jugadorIds, int $anio, AjustesIA $ajustes): array
    {
        $niveles = Jugador::whereIn('id', $jugadorIds)->pluck('nivel', 'id');

        $plus = match ($ajustes->prioridad_frenar_lider) {
            1 => 0.0,
            2 => 0.7,
            3 => 1.3,
            4 => 2.0,
            5 => 2.8,
            default => 1.3,
        };

        $liderId = null;
        if ($plus > 0) {
            $puntos  = Jugador::puntosPorJugador($anio);
            $liderId = collect($jugadorIds)
                ->sortByDesc(fn ($id) => (int) ($puntos[$id] ?? 0))
                ->first();
        }

        return collect($jugadorIds)->mapWithKeys(function ($id) use ($niveles, $liderId, $plus) {
            $nivel = (float) ($niveles[$id] ?? 5.0);

            return [$id => $id === $liderId ? round($nivel + $plus, 1) : $nivel];
        })->all();
    }

    // ── Validación estructural ─────────────────────────────────────────────

    /**
     * Comprueba que la asignación calculada es válida: por construcción
     * siempre debería serlo, pero es una red de seguridad barata.
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

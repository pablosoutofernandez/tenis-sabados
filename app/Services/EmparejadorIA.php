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
 * aquí mismo (calcularAsignacion, más abajo): recorre todos los repartos
 * posibles de los convocados en pistas de 4, puntúa cada uno según las
 * mismas reglas de siempre — no repetir parejas, no repetir rivales, cada
 * una pesando más cuanto más reciente fue la última vez (pesoRecencia,
 * dentro de EmparejadorIA), equilibrio, nivel_efectivo del líder — y se
 * queda con el mejor. Si hay varios igual de buenos, elige entre ellos al
 * azar, para no repetir siempre la misma "solución obvia".
 *
 * El nivel de cada jugador ya no lo revisa una IA tampoco: se ajusta solo
 * tras cada resultado con un sistema tipo Elo (ver App\Services\EloNiveles).
 */
class EmparejadorIA
{
    /**
     * Curvas de recencia (ver pesoRecencia): cuánto se multiplica el coste
     * de una repetición según cuántas jornadas atrás pasó.
     *
     * RIVALES decae en picado y sin suelo: a la jornada inmediatamente
     * anterior pesa 1, pero a la de hace 2 ya solo un 6% en "normal" — así
     * de tajante, porque lo único que de verdad importa por defecto es no
     * cruzarse con el mismo rival la semana siguiente. Eso sí, a partir de
     * ahí es lo único de las cuatro curvas de esta clase que SÍ cambia de
     * forma según la prioridad, no solo de magnitud (ver decayRival, más
     * abajo): en los niveles altos, la de hace 2 empieza a pesar de verdad
     * (hasta un 24% en el máximo), no solo la de la semana pasada.
     *
     * PAREJAS decae más despacio, con suelo en el 10%: a la de hace 2 pesa
     * un 54%, a la de hace 3 un 32%, a la de hace 4 ya solo un 21% — a
     * partir de ahí importa cada vez menos, hasta quedarse rondando ese
     * 10% de suelo por muy vieja que sea la repetición. La idea es que
     * hasta la 3ª o 4ª semana siga pesando bastante evitarla, pero pasado
     * ese punto le deje sitio de verdad al equilibrio de nivel — antes
     * (suelo en 18%) le costaba más ceder ese sitio.
     */
    private const SUELO_RIVAL  = 0.0;
    private const DECAY_PAREJA = 0.49;
    private const SUELO_PAREJA = 0.10;

    public function __construct(private HistorialTenis $historial)
    {
    }

    /**
     * @param  array<int>  $jugadorIds  Disponibles ese sábado.
     * @return array{no_juegan: array<int>, partidos: array<int, array>, explicacion: string}
     */
    /**
     * @param  array<int>  $jugadorIds  Disponibles ese sábado.
     * @param  array<string>  $excluirFirmas  Firmas de repartos ya vistos (ver
     *         firmaDeParticion) que no se deben repetir — es lo que usa
     *         "Probar otro emparejamiento" para no enseñar dos veces la
     *         misma agrupación de 4 en 4 dentro de la misma sesión.
     * @return array{no_juegan: array<int>, partidos: array<int, array>, explicacion: string, coste: float, firma: string}
     */
    public function proponer(Jornada $jornada, array $jugadorIds, array $excluirFirmas = []): array
    {
        $pistas = $this->historial->pistasPara(count($jugadorIds));

        if ($pistas < 1) {
            throw new RuntimeException('Hacen falta al menos 4 jugadores disponibles para montar una pista.');
        }

        $ajustes = AjustesIA::actuales();
        $anio    = (int) $jornada->fecha->year;

        $asignacion = $this->calcularAsignacion($jugadorIds, $pistas, $anio, $jornada->fecha, $ajustes, $excluirFirmas);
        $propuesta  = $this->redactar($asignacion, $anio, $ajustes);

        $this->validar($propuesta, $jugadorIds, $pistas);

        $propuesta['coste'] = $asignacion['coste'];
        $propuesta['firma'] = $asignacion['firma'];

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

        $ventanas = $this->ventanas($ajustes);

        $parejasVetadas  = collect($this->historial->parejasRecientes($ventanas['parejas']))
            ->keyBy(fn ($p) => implode('-', $p['ids']));
        $rivalesVetados  = collect($this->historial->rivalesRecientes($ventanas['rivales']))
            ->keyBy(fn ($p) => implode('-', $p['ids']));
        $partidosVetados = collect($this->historial->partidosRecientes($ventanas['partidos']))
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

            // Los cruces repetidos se agrupan en una sola línea por pista:
            // hay 4 por partido y en lista suelta inundarían el aviso.
            $cruces = [];

            foreach ($datos['equipo_a'] as $uno) {
                foreach ($datos['equipo_b'] as $otro) {
                    $clave = collect([(int) $uno, (int) $otro])->sort()->values()->implode('-');

                    if ($repetido = $rivalesVetados->get($clave)) {
                        $cruces[] = $repetido['nombres'].' (el '.$repetido['fecha'].')';
                    }
                }
            }

            if ($cruces) {
                $avisos[] = 'Pista '.($datos['pista'] ?? '?').': cruces que ya se dieron hace poco: '
                    .implode(', ', $cruces).'.';
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
     * Las particiones cuya firma (firmaDeParticion) esté en $excluirFirmas
     * se descartan directamente, como si no existieran — es lo que hace
     * que "Probar otro emparejamiento" encuentre de verdad una agrupación
     * distinta en vez de devolver la misma con las pistas renumeradas.
     *
     * @param  array<int>  $jugadorIds
     * @param  array<string>  $excluirFirmas
     * @return array{partidos: array<int, array{pista: int, equipo_a: array<int>, equipo_b: array<int>}>, no_juegan: array<int>, coste: float, firma: string}
     */
    private function calcularAsignacion(array $jugadorIds, int $pistas, int $anio, \Carbon\Carbon $antesDe, AjustesIA $ajustes, array $excluirFirmas = []): array
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

        [$companerosDist, $rivalesDist] = $this->historial->coincidenciasPorDistancia($anio, $antesDe);
        $niveles = $this->nivelesEfectivos($convocados, $anio, $ajustes);

        $pesoPareja     = $this->pesoPareja($ajustes);
        $pesoRival      = $this->pesoRival($ajustes);
        $decayRival     = $this->decayRival($ajustes);
        $pesoEquilibrio = $this->pesoEquilibrio($ajustes);

        // El coste de un cuarteto no depende de en qué pista caiga ni de lo
        // que pase en las otras, así que se calcula una sola vez por
        // cuarteto y se reutiliza: con 12 convocados hay 495 cuartetos
        // posibles frente a 5.775 repartos, y sin esta caché cada cuarteto
        // se recalcularía decenas de veces.
        $cacheCuartetos = [];
        $excluidas = array_flip($excluirFirmas);

        $mejorCoste = INF;
        $candidatos = [];

        foreach ($this->repartosPosibles($convocados, $porPista) as $particion) {
            $claves = array_map(fn ($cuarteto) => $this->clave($cuarteto), $particion);

            if (isset($excluidas[$this->firmaDeParticion($claves)])) {
                continue;
            }

            $costeTotal = 0.0;
            $pistasCalculadas = [];

            foreach ($particion as $indicePista => $cuarteto) {
                $clave = $claves[$indicePista];

                $cacheCuartetos[$clave] ??= $this->mejorSplitDeCuarteto(
                    $cuarteto, $niveles, $companerosDist, $rivalesDist,
                    $pesoPareja, $pesoRival, $decayRival, $pesoEquilibrio,
                );

                [$equipoA, $equipoB, $coste] = $cacheCuartetos[$clave];

                $costeTotal += $coste;
                $pistasCalculadas[] = [
                    'pista'    => $indicePista + 1,
                    'equipo_a' => $equipoA,
                    'equipo_b' => $equipoB,
                ];
            }

            // Se guardan todos los repartos que empatan de verdad con el
            // mejor visto hasta ahora, y al final se elige entre ellos al
            // azar; cuando aparece uno mejor, los que se quedan lejos se
            // descartan para no ir acumulando miles en memoria.
            if ($costeTotal < $mejorCoste) {
                $mejorCoste = $costeTotal;
                $candidatos = array_values(array_filter(
                    $candidatos,
                    fn ($c) => $c['coste'] <= $mejorCoste + 0.5,
                ));
            }

            if ($costeTotal <= $mejorCoste + 0.5) {
                $candidatos[] = ['coste' => $costeTotal, 'pistas' => $pistasCalculadas, 'claves' => $claves];
            }
        }

        if ($candidatos === []) {
            // Se han excluido todas las combinaciones razonables (solo
            // puede pasar tras pedir "otro" muchas veces seguidas con
            // pocos convocados). Se repite la búsqueda sin exclusiones
            // antes que fallar: peor una repetida que ninguna propuesta.
            return $this->calcularAsignacion($jugadorIds, $pistas, $anio, $antesDe, $ajustes, []);
        }

        $elegido = $candidatos[array_rand($candidatos)];

        return [
            'partidos'  => $elegido['pistas'],
            'no_juegan' => $noJuegan,
            'coste'     => $elegido['coste'],
            'firma'     => $this->firmaDeParticion($elegido['claves']),
        ];
    }

    /**
     * Los repartos de los convocados en pistas de 4 que hay que puntuar.
     *
     * Con 2 o 3 pistas (lo que permite el torneo) salen como mucho 5.775
     * repartos distintos, así que se recorren TODOS y el mejor es el mejor
     * de verdad. Antes se probaban unos miles al azar, que con un solo
     * objetivo bastaba; ahora que parejas y rivales compiten entre sí, la
     * mejor combinación suele ser una concreta y el muestreo se la dejaba
     * por el camino más veces de la cuenta.
     *
     * Si algún día se sube pistas_max y la cuenta se dispara, vuelve solo
     * al muestreo al azar de siempre.
     *
     * @param  array<int>  $convocados
     * @return iterable<int, array<int, array<int>>>
     */
    private function repartosPosibles(array $convocados, int $porPista): iterable
    {
        if ($this->cuantosRepartos(count($convocados), $porPista) <= 50000) {
            return $this->todosLosRepartos($convocados, $porPista);
        }

        return $this->repartosAlAzar($convocados, $porPista, (int) min(3000, max(400, count($convocados) * 150)));
    }

    /** Cuántos repartos distintos salen, para decidir si caben todos. */
    private function cuantosRepartos(int $convocados, int $porPista): float
    {
        $total = 1.0;

        for ($quedan = $convocados; $quedan > 0; $quedan -= $porPista) {
            // Fijado el primero que queda, sus compañeros de pista salen de
            // entre los demás; así cada reparto se cuenta una sola vez.
            $total *= $this->combinatorio($quedan - 1, $porPista - 1);

            if ($total > 1e9) {
                return $total;
            }
        }

        return $total;
    }

    private function combinatorio(int $de, int $tomando): float
    {
        $total = 1.0;

        for ($i = 0; $i < $tomando; $i++) {
            $total = $total * ($de - $i) / ($i + 1);
        }

        return $total;
    }

    /**
     * Todos los repartos posibles, sin repetir el mismo con las pistas en
     * otro orden: se fija el primer jugador que queda, se le buscan 3
     * compañeros y se sigue con el resto.
     *
     * @return \Generator<int, array<int, array<int>>>
     */
    private function todosLosRepartos(array $jugadores, int $porPista): \Generator
    {
        if ($jugadores === []) {
            yield [];

            return;
        }

        $primero = array_shift($jugadores);

        foreach ($this->combinaciones($jugadores, $porPista - 1) as [$acompanantes, $resto]) {
            foreach ($this->todosLosRepartos($resto, $porPista) as $siguientes) {
                yield [[$primero, ...$acompanantes], ...$siguientes];
            }
        }
    }

    /**
     * Cada forma de elegir $cuantos de la lista, junto con lo que sobra.
     *
     * @return \Generator<int, array{0: array<int>, 1: array<int>}>
     */
    private function combinaciones(array $jugadores, int $cuantos): \Generator
    {
        if ($cuantos === 0) {
            yield [[], $jugadores];

            return;
        }

        $total = count($jugadores);

        for ($i = 0; $i <= $total - $cuantos; $i++) {
            $elegido   = $jugadores[$i];
            $siguientes = array_slice($jugadores, $i + 1);
            $saltados   = array_slice($jugadores, 0, $i);

            foreach ($this->combinaciones($siguientes, $cuantos - 1) as [$grupo, $sobrantes]) {
                yield [[$elegido, ...$grupo], [...$saltados, ...$sobrantes]];
            }
        }
    }

    /**
     * Red de seguridad para cuando hay demasiados repartos: el muestreo al
     * azar de toda la vida.
     *
     * @return \Generator<int, array<int, array<int>>>
     */
    private function repartosAlAzar(array $jugadores, int $porPista, int $intentos): \Generator
    {
        for ($i = 0; $i < $intentos; $i++) {
            yield array_chunk(collect($jugadores)->shuffle()->values()->all(), $porPista);
        }
    }

    /** Clave estable de un grupo de jugadores, para cachés y vetos. */
    private function clave(array $jugadorIds): string
    {
        $ids = array_map('intval', $jugadorIds);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Firma estable de un reparto completo: qué 4 (y qué 4, y qué 4) van
     * juntos, sin importar el orden de las pistas ni quién quedó en cada
     * lado dentro de cada cuarteto — dos repartos con exactamente la misma
     * gente en las mismas pistas tienen la misma firma aunque el 2v2
     * interno sea distinto. Es lo que compara "Probar otro emparejamiento"
     * para saber si una combinación ya se enseñó esta sesión.
     *
     * @param  array<string>  $clavesDeCuarteto  Una clave() por cuarteto.
     */
    private function firmaDeParticion(array $clavesDeCuarteto): string
    {
        sort($clavesDeCuarteto);

        return implode('|', $clavesDeCuarteto);
    }

    /** De los 3 repartos posibles de 4 en 2v2, el de menor coste. */
    private function mejorSplitDeCuarteto(
        array $cuarteto,
        array $niveles,
        array $companerosDist,
        array $rivalesDist,
        float $pesoPareja,
        float $pesoRival,
        float $decayRival,
        float $pesoEquilibrio,
    ): array {
        [$a, $b, $c, $d] = $cuarteto;

        $opciones = [
            [[$a, $b], [$c, $d]],
            [[$a, $c], [$b, $d]],
            [[$a, $d], [$b, $c]],
        ];

        $mejores    = [];
        $mejorCoste = INF;

        foreach ($opciones as [$equipoA, $equipoB]) {
            $coste = $this->costePista(
                $equipoA, $equipoB, $niveles, $companerosDist, $rivalesDist,
                $pesoPareja, $pesoRival, $decayRival, $pesoEquilibrio,
            );

            // Si dos formas de partir el cuarteto valen lo mismo, se guardan
            // las dos y luego se echa a suertes, igual que con los repartos.
            if ($coste < $mejorCoste - 0.001) {
                $mejorCoste = $coste;
                $mejores    = [[$equipoA, $equipoB]];
            } elseif ($coste <= $mejorCoste + 0.001) {
                $mejorCoste = min($mejorCoste, $coste);
                $mejores[]  = [$equipoA, $equipoB];
            }
        }

        [$equipoA, $equipoB] = $mejores[array_rand($mejores)];

        return [$equipoA, $equipoB, $mejorCoste];
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
    /**
     * Cuánto pesa una repetición que pasó hace $n jornadas: 1.0 si fue la
     * jornada inmediatamente anterior, y decae desde ahí — geométricamente,
     * multiplicando por $decaimiento en cada jornada que se retrocede — sin
     * bajar nunca de $suelo por mucho que haga.
     *
     * Con $suelo en 0, la repetición acaba pesando prácticamente nada
     * (rivales); con $suelo por encima de 0, se queda para siempre en un
     * runrún de fondo por muy antigua que sea la repetición (parejas), que
     * es justo la diferencia que se busca entre las dos.
     */
    private function pesoRecencia(int $jornadasAtras, float $decaimiento, float $suelo): float
    {
        $n = max(1, $jornadasAtras);

        return $suelo + (1 - $suelo) * ($decaimiento ** ($n - 1));
    }

    /** Suma el peso de recencia de cada vez que ocurrió, para una lista de distancias. */
    private function costeRecenciaAcumulado(array $distancias, float $decaimiento, float $suelo): float
    {
        $total = 0.0;

        foreach ($distancias as $n) {
            $total += $this->pesoRecencia((int) $n, $decaimiento, $suelo);
        }

        return $total;
    }

    /**
     * Coste de una pista concreta. Cada vez que dos jugadores han sido
     * pareja o rivales esta temporada suma su propio coste, tanto más alto
     * cuanto más reciente fue (pesoRecencia, arriba): la de la semana
     * pasada cuenta casi como si fuera obligatorio evitarla, la de hace dos
     * semanas bastante menos, y a partir de la de hace tres los rivales ya
     * casi no computan — mientras que las parejas nunca llegan a pesar
     * cero del todo, por vieja que sea la repetición (DECAY_PAREJA /
     * SUELO_PAREJA más abajo).
     *
     * A esto se suma $pesoEquilibrio por cada punto de diferencia de
     * nivel_efectivo entre las dos parejas — al cuadrado, así que crece
     * rápido; sin umbral que la deje a cero por debajo de un mínimo, porque
     * eso hacía que la variedad ganara siempre en cualquier diferencia
     * moderada sin importar lo bajo que se pusiera su peso — y un matiz
     * fijo de peso pequeño que evita juntar al mejor nivel con el más
     * flojo como compañeros aunque la suma cuadre.
     */
    private function costePista(
        array $equipoA,
        array $equipoB,
        array $niveles,
        array $companerosDist,
        array $rivalesDist,
        float $pesoPareja,
        float $pesoRival,
        float $decayRival,
        float $pesoEquilibrio,
    ): float {
        $coste = 0.0;

        $coste += $pesoPareja * $this->costeRecenciaAcumulado(
            $companerosDist[$equipoA[0]][$equipoA[1]] ?? [], self::DECAY_PAREJA, self::SUELO_PAREJA,
        );
        $coste += $pesoPareja * $this->costeRecenciaAcumulado(
            $companerosDist[$equipoB[0]][$equipoB[1]] ?? [], self::DECAY_PAREJA, self::SUELO_PAREJA,
        );

        foreach ($equipoA as $x) {
            foreach ($equipoB as $y) {
                $coste += $pesoRival * $this->costeRecenciaAcumulado(
                    $rivalesDist[$x][$y] ?? [], $decayRival, self::SUELO_RIVAL,
                );
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
        [$companeros, $rivales] = $this->historial->coincidenciasCrudas($anio);

        $partidos = collect($asignacion['partidos'])->map(function (array $p) use ($niveles, $companeros, $rivales) {
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

            $crucesNuevos = collect($p['equipo_a'])->every(
                fn ($uno) => collect($p['equipo_b'])->every(
                    fn ($otro) => (int) ($rivales[$uno][$otro] ?? 0) === 0,
                ),
            );

            if ($crucesNuevos) {
                $frase .= ' Ninguno de los cuatro se había enfrentado antes esta temporada.';
            }

            $p['motivo'] = $frase;

            return $p;
        })->all();

        $explicacion = 'Repartidos '.count($partidos).' pistas evitando repetir con quien se jugó'
            .' hace poco (parejas y, aparte, rivales, pesando más lo más reciente) y cuadrando'
            .' el nivel dentro de cada pista.';

        return [
            'no_juegan'   => $asignacion['no_juegan'],
            'partidos'    => $partidos,
            'explicacion' => $explicacion,
        ];
    }

    // ── Prioridades del organizador (Ajustes IA) ────────────────────────────

    /**
     * Cuántas jornadas atrás mira cada AVISO (el "esto ya se repitió"
     * que ve el organizador al revisar la propuesta) — el coste que decide
     * la propia asignación ya no usa esto: ese usa coincidenciasPorDistancia()
     * y pesa cada repetición según su recencia real, no según si cae dentro
     * de una ventana. Esta ventana solo decide hasta cuándo merece la pena
     * avisar; una repetición de hace 10 jornadas ya pesa poquísimo en el
     * coste pero seguiría siendo ruido si se avisara de ella cada vez.
     *
     * La ventana base (config/tenis.php) se ensancha o se estrecha según la
     * misma prioridad que ya se usa para el coste. En el nivel máximo no es
     * "avisar un poco más atrás": es repasar toda la temporada jugada.
     *
     * @return array{parejas: int, rivales: int, partidos: int}
     */
    private function ventanas(AjustesIA $ajustes): array
    {
        $parejas = $ajustes->prioridad_no_repetir_parejas;
        $rivales = $ajustes->prioridad_no_repetir_rivales;

        return [
            'parejas'  => $this->ventana((int) config('tenis.no_repetir.parejas_ultimas_jornadas', 4), $parejas),
            'rivales'  => $this->ventana((int) config('tenis.no_repetir.rivales_ultimas_jornadas', 2), $rivales),
            'partidos' => $this->ventana((int) config('tenis.no_repetir.partidos_ultimas_jornadas', 4), max($parejas, $rivales)),
        ];
    }

    private function ventana(int $base, int $prioridad): int
    {
        if ($prioridad >= 5) {
            return max(1, Jornada::count());
        }

        $factor = match ($prioridad) {
            1 => 0.5,
            2 => 0.75,
            4 => 1.5,
            default => 1.0,
        };

        return max(1, (int) round($base * $factor));
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
     * Cuánto pesa, por cada vez que ha pasado esta temporada, que dos
     * jugadores hayan sido PAREJA — ya multiplicado por pesoRecencia(), así
     * que esto es el coste de una repetición de la semana pasada (n=1); una
     * de hace 2 o 3 semanas cuesta una fracción de esto, no esto mismo.
     *
     * Antes había, aparte, un veto fijo (+40) para cualquier repetición
     * dentro de una ventana de jornadas configurada aparte; ahora ya no
     * hace falta, pero a cambio este número tiene que ser bastante más alto
     * que el peso plano de antes para lograr el mismo efecto disuasorio
     * sobre una repetición de la semana pasada: en pruebas con temporadas
     * simuladas, con el peso plano de antes (16) las parejas se repetían
     * más rápido de lo que se pretendía porque ya no había veto que lo
     * evitara.
     */
    private function pesoPareja(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_no_repetir_parejas) {
            1 => 8.0,
            2 => 22.0,
            4 => 100.0,
            5 => 200.0,
            default => 45.0,
        };
    }

    /**
     * Igual que pesoPareja(), pero para haberse enfrentado. Sigue pesando
     * menos que repetir pareja a igualdad de ajuste (28 frente a 45 en el
     * nivel normal), que es como debe ser.
     */
    private function pesoRival(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_no_repetir_rivales) {
            1 => 6.0,
            2 => 14.0,
            4 => 62.0,
            5 => 126.0,
            default => 28.0,
        };
    }

    /**
     * Cuánto le llega a pesar la jornada de hace 2 semanas, en proporción a
     * la de la semana pasada (que siempre vale el 100%). En "normal" se
     * queda casi en nada (6%) — la idea de base sigue siendo que solo
     * importa de verdad no cruzarse la semana siguiente — pero al subir la
     * prioridad, además de pesar más en general (pesoRival, arriba), el
     * algoritmo empieza a acordarse también de hace 2 semanas, no solo de
     * la última.
     */
    private function decayRival(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_no_repetir_rivales) {
            1 => 0.30,
            2 => 0.40,
            4 => 0.60,
            5 => 0.70,
            default => 0.50,
        };
    }

    /**
     * Cuánto pesa, en el coste, cada punto de diferencia de nivel_efectivo
     * que pase del umbral. A más peso, más se sacrifica variedad con tal
     * de cuadrar las sumas.
     *
     * El "normal" (2.3) sale de un barrido fino entre 2.0 y 3.0 con la
     * misma simulación de temporada larga, con 14 semillas distintas para
     * no fiarse del ruido: 2.3 gana a 2.0 en las tres métricas a la vez
     * (equilibrio, avisos de desequilibrio Y avisos de repetición, sin
     * trade-off), y más allá de 2.3 el ruido de la simulación ya domina —
     * probado hasta 3.0, sin una tendencia clara de mejora. El resto de
     * niveles reescala en la misma proporción que antes (antes
     * 0.4/1.0/2.0/4.6/10.7 con el "normal" en 2.0).
     */
    private function pesoEquilibrio(AjustesIA $ajustes): float
    {
        return match ($ajustes->prioridad_equilibrio) {
            1 => 0.5,
            2 => 1.15,
            4 => 5.3,
            5 => 12.3,
            default => 2.3,
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
            2 => 1.5,
            3 => 3.0,
            4 => 4.5,
            5 => 6.5,
            default => 3.0,
        };

        // Empate a puntos en cabeza (pasa más de lo que parece: en cuanto
        // dos personas llevan el mismo número de jornadas jugadas, apenas
        // hace falta más para que coincidan). $jugadorIds llega ya barajado
        // desde calcularAsignacion —para decidir quién se queda sin pista si
        // sobran—, así que sin este ->sort() previo el empate lo resolvía
        // ese barajado ajeno: cada vez tocaba un líder distinto sin que
        // nada real hubiera cambiado. Se ordena por id antes de mirar
        // puntos para que el empate se resuelva siempre igual.
        $liderId = null;
        if ($plus > 0) {
            $puntos  = Jugador::puntosPorJugador($anio);
            $liderId = collect($jugadorIds)
                ->sort()->values()
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

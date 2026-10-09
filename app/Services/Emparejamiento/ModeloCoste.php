<?php

namespace App\Services\Emparejamiento;

/**
 * Puntúa pistas: cuanto más bajo el coste, mejor. Todo en la unidad de
 * Pesos (diferencia de suma de nivel al cuadrado).
 */
final class ModeloCoste
{
    /** @var array<string, array{0: array<int>, 1: array<int>, 2: float}> */
    private array $cache = [];

    /**
     * @param  array<int, float>  $efectivos  Nivel para cuadrar sumas (con el plus del líder).
     * @param  array  $companerosDist  [id => [otro => distancias en jornadas]]
     * @param  array  $rivalesDist     Igual, para rivales.
     */
    public function __construct(
        private readonly Pesos $pesos,
        private readonly array $efectivos,
        private readonly array $companerosDist,
        private readonly array $rivalesDist,
    ) {
    }

    /**
     * De las 3 formas de partir un cuarteto en 2 contra 2, la más barata.
     * El resultado no depende del resto del reparto, así que se cachea.
     *
     * @param  array<int>  $cuarteto
     * @return array{0: array<int>, 1: array<int>, 2: float} [equipo_a, equipo_b, coste]
     */
    public function mejorSplit(array $cuarteto, string $clave): array
    {
        return $this->cache[$clave] ??= $this->calcularMejorSplit($cuarteto);
    }

    private function calcularMejorSplit(array $cuarteto): array
    {
        [$a, $b, $c, $d] = $cuarteto;

        $mejores    = [];
        $mejorCoste = INF;

        foreach ([[[$a, $b], [$c, $d]], [[$a, $c], [$b, $d]], [[$a, $d], [$b, $c]]] as [$equipoA, $equipoB]) {
            $coste = $this->costePista($equipoA, $equipoB);

            // Empates reales se echan a suertes, igual que con los repartos.
            if ($coste < $mejorCoste - 0.001) {
                $mejorCoste = $coste;
                $mejores    = [[$equipoA, $equipoB]];
            } elseif ($coste <= $mejorCoste + 0.001) {
                $mejores[] = [$equipoA, $equipoB];
            }
        }

        [$equipoA, $equipoB] = $mejores[array_rand($mejores)];

        return [$equipoA, $equipoB, $mejorCoste];
    }

    /**
     * Coste de una pista concreta:
     *   · cada vez que una de las dos parejas ya jugó junta esta temporada,
     *     tanto más cuanto más reciente (nunca baja del suelo);
     *   · cada vez que dos rivales ya se enfrentaron, casi solo si fue la
     *     semana pasada;
     *   · la diferencia de suma de nivel efectivo, al cuadrado.
     *
     * Quién va con quién dentro de la pareja (fuerte con flojo o no) no se
     * penaliza: solo importan las sumas y las repeticiones.
     */
    public function costePista(array $equipoA, array $equipoB): float
    {
        return $this->desglose($equipoA, $equipoB)['total'];
    }

    /**
     * El coste de una pista término a término. costePista() es solo su
     * total, así que lo que enseña el detalle técnico es exactamente lo que
     * sumó la búsqueda. Las parejas y cruces sin historial no aparecen
     * (cuestan 0).
     *
     * @return array{
     *     equilibrio: array{suma_a: float, suma_b: float, diferencia: float, peso: float, coste: float},
     *     parejas: array<int, array{ids: array<int>, distancias: array<int>, factores: array<float>, peso: float, coste: float}>,
     *     rivales: array<int, array{ids: array<int>, distancias: array<int>, factores: array<float>, peso: float, coste: float}>,
     *     total: float,
     * }
     */
    public function desglose(array $equipoA, array $equipoB): array
    {
        $p = $this->pesos;

        $sumaA      = $this->efectivos[$equipoA[0]] + $this->efectivos[$equipoA[1]];
        $sumaB      = $this->efectivos[$equipoB[0]] + $this->efectivos[$equipoB[1]];
        $diferencia = abs($sumaA - $sumaB);

        $equilibrio = [
            'suma_a'     => $sumaA,
            'suma_b'     => $sumaB,
            'diferencia' => $diferencia,
            'peso'       => $p->equilibrio,
            'coste'      => $p->equilibrio * $diferencia ** 2,
        ];

        $parejas = [];
        foreach ([$equipoA, $equipoB] as [$x, $y]) {
            if ($termino = self::termino([$x, $y], $this->companerosDist[$x][$y] ?? [], $p->pareja, Pesos::DECAY_PAREJA, Pesos::SUELO_PAREJA)) {
                $parejas[] = $termino;
            }
        }

        $rivales = [];
        foreach ($equipoA as $x) {
            foreach ($equipoB as $y) {
                if ($termino = self::termino([$x, $y], $this->rivalesDist[$x][$y] ?? [], $p->rival, $p->decayRival, 0.0)) {
                    $rivales[] = $termino;
                }
            }
        }

        $total = $equilibrio['coste']
            + array_sum(array_column($parejas, 'coste'))
            + array_sum(array_column($rivales, 'coste'));

        return compact('equilibrio', 'parejas', 'rivales', 'total');
    }

    /** Un dúo con historial: cada vez que coincidieron, su factor de recencia, por el peso. */
    private static function termino(array $ids, array $distancias, float $peso, float $decay, float $suelo): ?array
    {
        if ($distancias === []) {
            return null;
        }

        sort($distancias);
        $factores = array_map(fn ($n) => self::pesoRecencia((int) $n, $decay, $suelo), $distancias);

        return [
            'ids'        => $ids,
            'distancias' => $distancias,
            'factores'   => $factores,
            'peso'       => $peso,
            'coste'      => $peso * array_sum($factores),
        ];
    }

    /**
     * Peso de una coincidencia de hace $n jornadas: 1 si fue la anterior,
     * multiplicado por $decay por cada jornada más atrás, sin bajar de $suelo.
     */
    public static function pesoRecencia(int $n, float $decay, float $suelo): float
    {
        return $suelo + (1 - $suelo) * ($decay ** (max(1, $n) - 1));
    }
}

<?php

namespace App\Services\Emparejamiento;

use App\Models\AjustesIA;

/**
 * Todas las escalas del emparejador en un solo sitio.
 *
 * La unidad común es el "punto de diferencia de suma al cuadrado": una pista
 * con 7+5 contra 6+4 (diferencia 2) cuesta 2² = 4 en equilibrio "normal".
 * Cada repetición se expresa en esa misma moneda, así que cada peso se lee
 * como "qué diferencia de nivel estoy dispuesto a aguantar para evitarla":
 *
 *   repetir pareja de la semana pasada (normal) = 9   → 3 puntos de diferencia
 *   repetir UN cruce de la semana pasada (normal) = 5.4 → 2.3 puntos
 *
 * Calibrado para un grupo con niveles entre ~3 y ~8, donde una diferencia
 * de suma de 3 ya se considera partido descompensado.
 *
 * Los cinco niveles de cada ajuste multiplican la base por el mismo factor
 * (FACTORES), así que mover un ajuste un nivel siempre tiene el mismo
 * efecto relativo, sea cual sea.
 */
final class Pesos
{
    /** Multiplicador por nivel de prioridad (1 = baja … 5 = máxima). */
    public const FACTORES = [1 => 0.2, 2 => 0.5, 3 => 1.0, 4 => 2.0, 5 => 5.0];

    /** Coste base de repetir pareja de la semana pasada (3² → 3 puntos de diferencia). */
    public const BASE_PAREJA = 9.0;

    /** Coste base de repetir un cruce de la semana pasada (por cada uno de los 4 cruces). */
    public const BASE_RIVAL = 5.4;

    /**
     * Recencia de las parejas: a la jornada anterior pesa 100%, a la de hace
     * 2 un 54%, hace 3 un 32%, hace 4 un 21%… y nunca baja del 10%.
     */
    public const DECAY_PAREJA = 0.49;
    public const SUELO_PAREJA = 0.10;

    /**
     * Recencia de los rivales: cuánto pesa la jornada de hace 2 respecto a
     * la anterior. En normal casi nada (6%): lo que importa es no cruzarse
     * la semana siguiente. Sin suelo: los cruces viejos acaban sin pesar.
     */
    public const DECAY_RIVAL = [1 => 0.03, 2 => 0.04, 3 => 0.06, 4 => 0.12, 5 => 0.24];

    /**
     * Plus de nivel máximo para el líder, que se alcanza cuando saca
     * VENTAJA_PLENA_LIDER puntos al segundo de los convocados. Con menos
     * ventaja el plus es proporcional; con empate en cabeza, cero.
     */
    public const PLUS_LIDER = [1 => 0.0, 2 => 0.75, 3 => 1.5, 4 => 2.5, 5 => 4.0];
    public const VENTAJA_PLENA_LIDER = 3;

    /** Dos repartos cuyo coste difiere menos que esto se consideran empate. */
    public const TOLERANCIA_EMPATE = 0.25;

    public function __construct(
        public readonly float $pareja,
        public readonly float $rival,
        public readonly float $decayRival,
        public readonly float $equilibrio,
        public readonly float $plusLider,
    ) {
    }

    public static function desde(AjustesIA $ajustes): self
    {
        return new self(
            pareja:     self::BASE_PAREJA * self::factor($ajustes->prioridad_no_repetir_parejas),
            rival:      self::BASE_RIVAL * self::factor($ajustes->prioridad_no_repetir_rivales),
            decayRival: self::DECAY_RIVAL[self::nivel($ajustes->prioridad_no_repetir_rivales)],
            equilibrio: self::factor($ajustes->prioridad_equilibrio),
            plusLider:  self::PLUS_LIDER[self::nivel($ajustes->prioridad_frenar_lider)],
        );
    }

    private static function factor(?int $prioridad): float
    {
        return self::FACTORES[self::nivel($prioridad)];
    }

    private static function nivel(?int $prioridad): int
    {
        return max(1, min(5, $prioridad ?? 3));
    }
}

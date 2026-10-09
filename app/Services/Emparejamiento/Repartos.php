<?php

namespace App\Services\Emparejamiento;

/**
 * Las formas de repartir a los convocados en pistas de 4.
 *
 * Con 2 o 3 pistas salen como mucho 5.775 repartos distintos, así que se
 * recorren todos y el mejor es el mejor de verdad. Si algún día se sube
 * pistas_max y la cuenta se dispara, se pasa a muestreo al azar.
 */
final class Repartos
{
    private const MAX_EXHAUSTIVO = 50000;

    /**
     * @param  array<int>  $convocados
     * @return iterable<int, array<int, array<int>>>
     */
    public static function posibles(array $convocados, int $porPista): iterable
    {
        if (self::cuantos(count($convocados), $porPista) <= self::MAX_EXHAUSTIVO) {
            return self::todos($convocados, $porPista);
        }

        return self::alAzar($convocados, $porPista, (int) min(3000, max(400, count($convocados) * 150)));
    }

    /** Clave estable de un grupo de jugadores. */
    public static function clave(array $jugadorIds): string
    {
        $ids = array_map('intval', $jugadorIds);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Firma de un reparto completo: qué cuartetos van juntos, sin importar
     * el orden de las pistas ni el 2 contra 2 interno. Es lo que compara
     * "Probar otro emparejamiento" para no repetir agrupación.
     *
     * @param  array<string>  $clavesDeCuarteto
     */
    public static function firma(array $clavesDeCuarteto): string
    {
        sort($clavesDeCuarteto);

        return implode('|', $clavesDeCuarteto);
    }

    private static function cuantos(int $convocados, int $porPista): float
    {
        $total = 1.0;

        for ($quedan = $convocados; $quedan > 0; $quedan -= $porPista) {
            // Fijado el primero que queda, sus compañeros de pista salen de
            // entre los demás; así cada reparto se cuenta una sola vez.
            $total *= self::combinatorio($quedan - 1, $porPista - 1);
        }

        return $total;
    }

    private static function combinatorio(int $de, int $tomando): float
    {
        $total = 1.0;

        for ($i = 0; $i < $tomando; $i++) {
            $total = $total * ($de - $i) / ($i + 1);
        }

        return $total;
    }

    /** @return \Generator<int, array<int, array<int>>> */
    private static function todos(array $jugadores, int $porPista): \Generator
    {
        if ($jugadores === []) {
            yield [];

            return;
        }

        $primero = array_shift($jugadores);

        foreach (self::combinaciones($jugadores, $porPista - 1) as [$acompanantes, $resto]) {
            foreach (self::todos($resto, $porPista) as $siguientes) {
                yield [[$primero, ...$acompanantes], ...$siguientes];
            }
        }
    }

    /** @return \Generator<int, array{0: array<int>, 1: array<int>}> */
    private static function combinaciones(array $jugadores, int $cuantos): \Generator
    {
        if ($cuantos === 0) {
            yield [[], $jugadores];

            return;
        }

        $total = count($jugadores);

        for ($i = 0; $i <= $total - $cuantos; $i++) {
            $saltados = array_slice($jugadores, 0, $i);

            foreach (self::combinaciones(array_slice($jugadores, $i + 1), $cuantos - 1) as [$grupo, $sobrantes]) {
                yield [[$jugadores[$i], ...$grupo], [...$saltados, ...$sobrantes]];
            }
        }
    }

    /** @return \Generator<int, array<int, array<int>>> */
    private static function alAzar(array $jugadores, int $porPista, int $intentos): \Generator
    {
        for ($i = 0; $i < $intentos; $i++) {
            shuffle($jugadores);

            yield array_chunk($jugadores, $porPista);
        }
    }
}

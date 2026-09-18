<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AjustesIA extends Model
{
    protected $table = 'ajustes_ia';

    protected $fillable = [
        'prioridad_equilibrio',
        'prioridad_no_repetir_parejas',
        'prioridad_no_repetir_rivales',
        'prioridad_frenar_lider',
    ];

    protected $casts = [
        'prioridad_equilibrio'         => 'integer',
        'prioridad_no_repetir_parejas' => 'integer',
        'prioridad_no_repetir_rivales' => 'integer',
        'prioridad_frenar_lider'       => 'integer',
    ];

    /** Siempre la misma fila (id 1); se crea sola con valores por defecto. */
    public static function actuales(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'prioridad_equilibrio'         => 3,
            'prioridad_no_repetir_parejas' => 3,
            'prioridad_no_repetir_rivales' => 3,
            'prioridad_frenar_lider'       => 3,
        ]);
    }

    public static function etiqueta(int $nivel): string
    {
        return match (true) {
            $nivel <= 1 => 'baja',
            $nivel === 2 => 'algo baja',
            $nivel === 3 => 'normal',
            $nivel === 4 => 'alta',
            default => 'máxima',
        };
    }

    public static function fraseParejas(int $nivel): string
    {
        return match ($nivel) {
            1 => 'casi no evita repetir parejas',
            2 => 'evita repetir parejas un poco',
            4 => 'evita repetir parejas bastante',
            5 => 'evita repetir parejas a toda costa',
            default => 'evita repetir parejas lo normal',
        };
    }

    public static function fraseRivales(int $nivel): string
    {
        return match ($nivel) {
            1 => 'casi no mira los cruces',
            2 => 'evita repetir rivales un poco',
            4 => 'evita repetir rivales bastante',
            5 => 'evita repetir rivales a toda costa',
            default => 'evita repetir rivales lo normal',
        };
    }

    public static function fraseEquilibrio(int $nivel): string
    {
        return match ($nivel) {
            1 => 'casi no le importa el equilibrio',
            2 => 'le importa poco el equilibrio',
            4 => 'le importa mucho el equilibrio',
            5 => 'exige equilibrio casi perfecto',
            default => 'equilibrio normal',
        };
    }

    public static function fraseLider(int $nivel): string
    {
        return match ($nivel) {
            1 => 'no frena al líder',
            2 => 'frena poco al líder',
            4 => 'frena bastante al líder',
            5 => 'frena mucho al líder',
            default => 'frena al líder lo normal',
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AjustesIA extends Model
{
    protected $table = 'ajustes_ia';

    protected $fillable = [
        'prioridad_equilibrio',
        'prioridad_no_repetir',
        'prioridad_frenar_lider',
    ];

    protected $casts = [
        'prioridad_equilibrio'   => 'integer',
        'prioridad_no_repetir'   => 'integer',
        'prioridad_frenar_lider' => 'integer',
    ];

    /** Siempre la misma fila (id 1); se crea sola con valores por defecto. */
    public static function actuales(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'prioridad_equilibrio'   => 3,
            'prioridad_no_repetir'   => 3,
            'prioridad_frenar_lider' => 3,
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

    public static function fraseVariedad(int $nivel): string
    {
        return match ($nivel) {
            1 => 'casi no evita repetir',
            2 => 'evita repetir un poco',
            4 => 'evita repetir bastante',
            5 => 'evita repetir a toda costa',
            default => 'evita repetir lo normal',
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

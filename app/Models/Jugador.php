<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Jugador extends Model
{
    protected $table = 'jugadores';

    protected $fillable = ['nombre', 'nivel', 'activo', 'es_refuerzo'];

    protected $casts = [
        'nivel'       => 'float',
        'activo'      => 'boolean',
        'es_refuerzo' => 'boolean',
    ];

    public function partidos(): BelongsToMany
    {
        return $this->belongsToMany(Partido::class, 'partido_jugador')
            ->withPivot('equipo', 'puntos');
    }

    public function jornadas(): BelongsToMany
    {
        return $this->belongsToMany(Jornada::class, 'jornada_jugador')
            ->withPivot('fuera');
    }

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('activo', true);
    }

    public function scopeBuscar(Builder $q, ?string $texto): Builder
    {
        return $q->when($texto, fn ($q) => $q->where('nombre', 'like', '%'.$texto.'%'));
    }

    public function getInicialesAttribute(): string
    {
        $partes = preg_split('/\s+/', trim($this->nombre));

        return mb_strtoupper(mb_substr($partes[0], 0, 1).(isset($partes[1]) ? mb_substr($partes[1], 0, 1) : ''));
    }

    /**
     * Puntos ganados en partidos jugados en la app, por jugador y año:
     * [jugador_id => puntos]. No incluye los ajustes manuales.
     */
    public static function puntosPartidos(?int $anio = null): Collection
    {
        return DB::table('partido_jugador as pj')
            ->join('partidos as p', 'p.id', '=', 'pj.partido_id')
            ->join('jornadas as j', 'j.id', '=', 'p.jornada_id')
            ->when($anio, fn ($q) => $q->whereYear('j.fecha', $anio))
            ->groupBy('pj.jugador_id')
            ->pluck(DB::raw('SUM(pj.puntos) as total'), 'pj.jugador_id');
    }

    /**
     * Puntuación de partida fijada a mano por jugador y año, al margen de
     * los partidos: lo que ya llevabais antes de usar la app, o una
     * corrección puntual. Ver Livewire\Clasificacion para editarla.
     */
    public static function ajustesPorJugador(?int $anio = null): Collection
    {
        return DB::table('ajustes_puntos')
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->groupBy('jugador_id')
            ->pluck(DB::raw('SUM(puntos) as total'), 'jugador_id');
    }

    /**
     * Puntos totales por jugador y año: partidos jugados en la app más el
     * ajuste manual. Esto es lo que ve la IA al montar una jornada, así que
     * un ajuste manual sí afecta a "frenar al líder" y al resto de criterios.
     */
    public static function puntosPorJugador(?int $anio = null): Collection
    {
        $partidos = static::puntosPartidos($anio);
        $ajustes  = static::ajustesPorJugador($anio);

        return $partidos->keys()->merge($ajustes->keys())->unique()
            ->mapWithKeys(fn ($id) => [$id => (int) ($partidos[$id] ?? 0) + (int) ($ajustes[$id] ?? 0)]);
    }

    public static function partidosJugadosPorJugador(?int $anio = null): Collection
    {
        return DB::table('partido_jugador as pj')
            ->join('partidos as p', 'p.id', '=', 'pj.partido_id')
            ->join('jornadas as j', 'j.id', '=', 'p.jornada_id')
            ->whereNotNull('p.sets_a')
            ->when($anio, fn ($q) => $q->whereYear('j.fecha', $anio))
            ->groupBy('pj.jugador_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'pj.jugador_id');
    }

    /** Clasificación ordenada: puntos totales, desglose y partidos jugados.
     *  No incluye a los jugadores de refuerzo — no compiten por la temporada. */
    public static function clasificacion(?int $anio = null): Collection
    {
        $puntosPartidos = static::puntosPartidos($anio);
        $ajustes        = static::ajustesPorJugador($anio);
        $jugados        = static::partidosJugadosPorJugador($anio);

        return static::query()
            ->where('es_refuerzo', false)
            ->orderBy('nombre')
            ->get()
            ->map(fn (Jugador $j) => (object) [
                'id'              => $j->id,
                'nombre'          => $j->nombre,
                'nivel'           => $j->nivel,
                'activo'          => $j->activo,
                'puntos_partidos' => (int) ($puntosPartidos[$j->id] ?? 0),
                'ajuste'          => (int) ($ajustes[$j->id] ?? 0),
                'puntos'          => (int) ($puntosPartidos[$j->id] ?? 0) + (int) ($ajustes[$j->id] ?? 0),
                'jugados'         => (int) ($jugados[$j->id] ?? 0),
            ])
            ->sortByDesc('puntos')
            ->values();
    }
}

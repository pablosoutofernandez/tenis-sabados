<?php

namespace App\Models;

use App\Services\EloNiveles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Jornada extends Model
{
    protected $fillable = ['fecha', 'pistas', 'estado', 'explicacion_ia'];

    protected $casts = [
        'fecha'  => 'date',
        'pistas' => 'integer',
    ];

    protected static function booted(): void
    {
        // Al borrar una jornada, sus partidos y las parejas asociadas se
        // van con ella de forma explícita, sin depender de que la base de
        // datos tenga las claves foráneas activadas — si alguna vez se
        // desactivan (o algo falla a mitad de camino), esto sigue
        // funcionando igual y no deja partidos huérfanos por ahí.
        static::deleting(function (Jornada $jornada) {
            // Antes de borrar cada partido, revierte el ajuste de Elo que
            // se le hubiera aplicado — si no, el nivel de los jugadores se
            // queda con un ajuste "fantasma" de un partido que ya no existe.
            foreach ($jornada->partidos as $partido) {
                app(EloNiveles::class)->revertir($partido);
            }

            $partidoIds = $jornada->partidos()->pluck('id');

            DB::table('partido_jugador')->whereIn('partido_id', $partidoIds)->delete();
            DB::table('partidos')->where('jornada_id', $jornada->id)->delete();
            DB::table('jornada_jugador')->where('jornada_id', $jornada->id)->delete();
        });
    }

    public function partidos(): HasMany
    {
        return $this->hasMany(Partido::class)->orderBy('pista');
    }

    /** Todos los que dijeron que venían ese sábado. */
    public function jugadores(): BelongsToMany
    {
        return $this->belongsToMany(Jugador::class, 'jornada_jugador')
            ->withPivot('fuera')
            ->orderBy('nombre');
    }

    /** Los que tienen pista asignada. */
    public function alineados(): BelongsToMany
    {
        return $this->jugadores()->wherePivot('fuera', false);
    }

    /** Disponibles que se quedan sin jugar porque no salían las cuentas. */
    public function sinPista(): BelongsToMany
    {
        return $this->jugadores()->wherePivot('fuera', true);
    }

    public function estaCompleta(): bool
    {
        return $this->partidos->isNotEmpty()
            && $this->partidos->every(fn (Partido $p) => $p->jugado());
    }

    public function getEtiquetaAttribute(): string
    {
        return $this->fecha->translatedFormat('l j \d\e F Y');
    }

    /** Borra los emparejamientos actuales para volver a generarlos. */
    public function limpiarPartidos(): void
    {
        foreach ($this->partidos as $partido) {
            app(EloNiveles::class)->revertir($partido);
        }

        $this->partidos()->each(fn (Partido $p) => $p->delete());

        DB::table('jornada_jugador')
            ->where('jornada_id', $this->id)
            ->update(['fuera' => false]);
    }
}

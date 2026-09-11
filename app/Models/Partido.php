<?php

namespace App\Models;

use App\Services\EloNiveles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Partido extends Model
{
    protected $fillable = [
        'jornada_id', 'pista', 'hora', 'sets_a', 'sets_b',
        'retirado_id', 'super_tie_break', 'motivo_ia',
    ];

    protected $casts = [
        'pista'           => 'integer',
        'sets_a'          => 'integer',
        'sets_b'          => 'integer',
        'super_tie_break' => 'boolean',
    ];

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(Jornada::class);
    }

    public function jugadores(): BelongsToMany
    {
        return $this->belongsToMany(Jugador::class, 'partido_jugador')
            ->withPivot('equipo', 'puntos', 'nivel_delta');
    }

    public function equipo(string $letra): Collection
    {
        return $this->jugadores->where('pivot.equipo', $letra)->values();
    }

    public function equipoA(): Collection
    {
        return $this->equipo('a');
    }

    public function equipoB(): Collection
    {
        return $this->equipo('b');
    }

    public function jugado(): bool
    {
        return $this->sets_a !== null && $this->sets_b !== null;
    }

    /**
     * Guarda el resultado y reparte puntos según las normas del torneo:
     *   · 1 punto por set ganado, máximo 3 por jugador y partido.
     *   · Si alguien se retira: conserva los sets ya ganados, su compañero suma
     *     1 punto extra y la pareja rival se anota los 3 puntos.
     * Además, ajusta el nivel de los 4 jugadores al estilo Elo (ver
     * App\Services\EloNiveles) — salvo que haya retirada, que no mueve nivel.
     */
    public function registrarResultado(int $setsA, int $setsB, ?int $retiradoId = null, bool $superTieBreak = false): void
    {
        $max = (int) config('tenis.puntos_max_por_partido', 3);

        DB::transaction(function () use ($setsA, $setsB, $retiradoId, $superTieBreak, $max) {
            $this->update([
                'sets_a'          => $setsA,
                'sets_b'          => $setsB,
                'retirado_id'     => $retiradoId,
                'super_tie_break' => $superTieBreak,
            ]);

            $this->load('jugadores');

            $equipoRetirado = $retiradoId
                ? $this->jugadores->firstWhere('id', $retiradoId)?->pivot->equipo
                : null;

            foreach ($this->jugadores as $jugador) {
                $propios = $jugador->pivot->equipo === 'a' ? $setsA : $setsB;
                $puntos  = min($propios, $max);

                if ($equipoRetirado !== null) {
                    if ($jugador->id === $retiradoId) {
                        $puntos = min($propios, $max);              // conserva los ganados
                    } elseif ($jugador->pivot->equipo === $equipoRetirado) {
                        $puntos = min($propios + 1, $max);          // compañero: 1 más
                    } else {
                        $puntos = $max;                             // rivales: los 3 puntos
                    }
                }

                $this->jugadores()->updateExistingPivot($jugador->id, ['puntos' => $puntos]);
            }

            app(EloNiveles::class)->aplicar($this);
        });
    }

    public function borrarResultado(): void
    {
        DB::transaction(function () {
            app(EloNiveles::class)->revertir($this);

            $this->update(['sets_a' => null, 'sets_b' => null, 'retirado_id' => null, 'super_tie_break' => false]);
            DB::table('partido_jugador')->where('partido_id', $this->id)->update(['puntos' => 0]);
        });
    }
}

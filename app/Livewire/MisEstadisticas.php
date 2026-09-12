<?php

namespace App\Livewire;

use App\Models\Jugador;
use App\Models\Partido;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class MisEstadisticas extends Component
{
    public int $anio = 0;

    public function mount(): void
    {
        $this->anio = (int) now()->year;
    }

    public function render()
    {
        $jugador = Auth::user()->jugador;

        if (! $jugador) {
            return view('livewire.mis-estadisticas', [
                'jugador' => null,
            ]);
        }

        $partidos = Partido::query()
            ->whereHas('jugadores', fn ($q) => $q->where('jugadores.id', $jugador->id))
            ->whereNotNull('sets_a')
            ->whereHas('jornada', fn ($q) => $q->whereYear('fecha', $this->anio))
            ->with(['jugadores', 'jornada'])
            ->get()
            ->sortByDesc(fn (Partido $p) => $p->jornada->fecha)
            ->values();

        $victorias  = 0;
        $derrotas   = 0;
        $companeros = [];
        $recientes  = [];

        foreach ($partidos as $partido) {
            $yo = $partido->jugadores->firstWhere('id', $jugador->id);
            if (! $yo) {
                continue;
            }

            $miEquipo   = $yo->pivot->equipo;
            $misSets    = $miEquipo === 'a' ? $partido->sets_a : $partido->sets_b;
            $rivalSets  = $miEquipo === 'a' ? $partido->sets_b : $partido->sets_a;
            $gane       = $misSets > $rivalSets;

            $gane ? $victorias++ : $derrotas++;

            $companero = $partido->jugadores->first(fn ($j) => $j->pivot->equipo === $miEquipo && $j->id !== $jugador->id);
            if ($companero) {
                $companeros[$companero->nombre] = ($companeros[$companero->nombre] ?? 0) + 1;
            }

            $recientes[] = [
                'fecha'     => $partido->jornada->fecha,
                'gane'      => $gane,
                'marcador'  => $misSets.'-'.$rivalSets,
                'companero' => $companero?->nombre,
                'delta'     => (float) $yo->pivot->nivel_delta,
                'retirado'  => $partido->retirado_id !== null,
            ];
        }

        arsort($companeros);

        return view('livewire.mis-estadisticas', [
            'jugador'          => $jugador,
            'puntos'           => (int) (Jugador::puntosPorJugador($this->anio)[$jugador->id] ?? 0),
            'partidosJugados'  => $partidos->count(),
            'victorias'        => $victorias,
            'derrotas'         => $derrotas,
            'companeroFavorito' => array_key_first($companeros),
            'recientes'        => array_slice($recientes, 0, 10),
        ]);
    }
}

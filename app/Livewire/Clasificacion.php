<?php

namespace App\Livewire;

use App\Models\Auditoria;
use App\Models\Jornada;
use App\Models\Jugador;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Clasificacion extends Component
{
    public int  $anio = 0;
    public bool $soloActivos = true;

    // Edición manual de puntuación, al margen de los partidos.
    public bool  $editando = false;
    public array $ajustesForm = [];   // [jugador_id => puntos]

    public function mount(): void
    {
        // Pública: cualquiera puede consultarla sin cuenta. El único que la
        // tiene vetada es el organizador, que reparte las jornadas a ciegas.
        if (Auth::check()) {
            Gate::authorize('ver-puntuaciones');
        }

        $this->anio = (int) now()->year;
    }

    public function updatedAnio(): void
    {
        // Si cambias de año con el panel abierto, refresca los valores.
        if ($this->editando) {
            $this->abrirEdicion();
        }
    }

    public function abrirEdicion(): void
    {
        Gate::authorize('gestionar-jugadores');

        $ajustes = Jugador::ajustesPorJugador($this->anio);

        $this->ajustesForm = Jugador::orderBy('nombre')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => (int) ($ajustes[$id] ?? 0)])
            ->all();

        $this->editando = true;
    }

    public function cancelarEdicion(): void
    {
        $this->editando    = false;
        $this->ajustesForm = [];
    }

    public function guardarAjustes(): void
    {
        Gate::authorize('gestionar-jugadores');

        foreach ($this->ajustesForm as $jugadorId => $puntos) {
            DB::table('ajustes_puntos')->updateOrInsert(
                ['jugador_id' => $jugadorId, 'anio' => $this->anio],
                ['puntos' => (int) $puntos, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $this->editando    = false;
        $this->ajustesForm = [];

        Auditoria::registrar('puntos.ajustados', 'Puntuación de partida del año '.$this->anio.' actualizada a mano.');
        session()->flash('success', 'Puntuación de partida actualizada.');
    }

    public function render()
    {
        $filas = Jugador::clasificacion($this->anio)
            ->when($this->soloActivos, fn ($c) => $c->where('activo', true))
            ->values();

        // La escala crece de 10 en 10 según el líder; nunca baja de 30 columnas
        // ni de la escala configurada del cartel.
        $lider  = (int) ($filas->max('puntos') ?? 0);
        $escala = max(
            30,
            (int) config('tenis.escala_clasificacion', 100),
            (int) (ceil(max($lider, 1) / 10) * 10),
        );

        $anios = Jornada::orderByDesc('fecha')->pluck('fecha')
            ->map(fn ($f) => (int) $f->year)
            ->push((int) now()->year)
            ->unique()->sortDesc()->values();

        $jugadoresEdicion = $this->editando
            ? Jugador::orderBy('nombre')->get(['id', 'nombre'])
            : collect();

        return view('livewire.clasificacion', [
            'filas'            => $filas,
            'escala'           => $escala,
            'anios'            => $anios,
            'normas'           => config('tenis.normas'),
            'jugadoresEdicion' => $jugadoresEdicion,
        ]);
    }
}

<?php

namespace App\Livewire;

use App\Models\Auditoria;
use App\Models\Jornada;
use App\Models\Partido;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class HistorialPartidos extends Component
{
    use WithPagination;

    /** Resultados en edición: [partido_id => ['sets_a'=>x,'sets_b'=>y,'retirado_id'=>z]] */
    public array $resultados = [];

    public ?int $editando = null;

    public function abrir(int $partidoId): void
    {
        $partido = Partido::with('jugadores')->findOrFail($partidoId);

        Gate::authorize('registrar-resultado', $partido);

        $this->editando = $partidoId;
        $this->resultados[$partidoId] = [
            'sets_a'      => $partido->sets_a ?? 0,
            'sets_b'      => $partido->sets_b ?? 0,
            'retirado_id' => $partido->retirado_id,
        ];
    }

    public function cerrar(): void
    {
        $this->editando = null;
    }

    public function guardarResultado(int $partidoId): void
    {
        $partido = Partido::with('jugadores')->findOrFail($partidoId);

        Gate::authorize('registrar-resultado', $partido);

        $datos = $this->resultados[$partidoId] ?? null;
        if (! $datos) {
            return;
        }

        // El select devuelve '' cuando no se retiró nadie.
        $datos['retirado_id'] = $datos['retirado_id'] !== '' ? $datos['retirado_id'] : null;

        $validado = validator($datos, [
            'sets_a'      => ['required', 'integer', 'min:0', 'max:5'],
            'sets_b'      => ['required', 'integer', 'min:0', 'max:5'],
            'retirado_id' => ['nullable', 'integer', 'exists:jugadores,id'],
        ], [], [
            'sets_a' => 'sets de la pareja A',
            'sets_b' => 'sets de la pareja B',
        ])->validate();

        $eraNuevo = ! $partido->jugado();

        $partido->registrarResultado(
            (int) $validado['sets_a'],
            (int) $validado['sets_b'],
            $validado['retirado_id'] ? (int) $validado['retirado_id'] : null,
        );

        $partido->load('jugadores');

        Auditoria::registrar(
            'resultado.guardado',
            ($eraNuevo ? 'Resultado anotado' : 'Resultado corregido').': '
                .$partido->equipo('a')->pluck('nombre')->join(' + ').' '
                .$validado['sets_a'].'-'.$validado['sets_b'].' '
                .$partido->equipo('b')->pluck('nombre')->join(' + ')
                .' (pista '.$partido->pista.', '.$partido->jornada->fecha->format('d/m/Y').').',
            $partido->jornada_id,
        );

        $this->editando = null;
        session()->flash('success', 'Resultado guardado. La clasificación ya está actualizada.');
    }

    public function borrarResultado(int $partidoId): void
    {
        $partido = Partido::with('jugadores')->findOrFail($partidoId);

        Gate::authorize('registrar-resultado', $partido);

        $descripcion = $partido->equipo('a')->pluck('nombre')->join(' + ').' '
            .$partido->sets_a.'-'.$partido->sets_b.' '
            .$partido->equipo('b')->pluck('nombre')->join(' + ')
            .' (pista '.$partido->pista.', '.$partido->jornada->fecha->format('d/m/Y').')';

        $partido->borrarResultado();

        Auditoria::registrar('resultado.borrado', 'Resultado borrado: '.$descripcion.'.', $partido->jornada_id);
        session()->flash('success', 'Resultado borrado.');
    }

    public function render()
    {
        $jornadas = Jornada::with(['partidos.jugadores', 'sinPista'])
            ->orderByDesc('fecha')
            ->paginate(5);

        return view('livewire.historial-partidos', [
            'jornadas'        => $jornadas,
            'verPuntuaciones' => Auth::user()?->puedeVerPuntuaciones() ?? true,
        ]);
    }
}

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

    /**
     * Resultados en edición:
     * [partido_id => ['sets' => [['a'=>x,'b'=>y,'tie_break'=>bool], ...], 'retirado_id' => z]]
     * Siempre 3 filas en el formulario; la tercera (el súper tie-break, si
     * lo hubo) se deja vacía (0-0) si el partido acabó en 2 sets.
     */
    public array $resultados = [];

    public ?int $editando = null;

    public function abrir(int $partidoId): void
    {
        $partido = Partido::with('jugadores')->findOrFail($partidoId);

        Gate::authorize('registrar-resultado', $partido);

        $sets = $partido->detalle_sets ?: [];
        while (count($sets) < 3) {
            $sets[] = ['a' => 0, 'b' => 0, 'tie_break' => count($sets) === 2];
        }

        $this->editando = $partidoId;
        $this->resultados[$partidoId] = [
            'sets'        => $sets,
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
            'sets'                => ['required', 'array', 'size:3'],
            'sets.0.a'            => ['required', 'integer', 'min:0', 'max:30'],
            'sets.0.b'            => ['required', 'integer', 'min:0', 'max:30', 'different:sets.0.a'],
            'sets.1.a'            => ['required', 'integer', 'min:0', 'max:30'],
            'sets.1.b'            => ['required', 'integer', 'min:0', 'max:30', 'different:sets.1.a'],
            'sets.2.a'            => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.2.b'            => ['nullable', 'integer', 'min:0', 'max:30'],
            'retirado_id'         => ['nullable', 'integer', 'exists:jugadores,id'],
        ], [
            'sets.0.b.different' => 'El set 1 no puede acabar en empate: alguien tiene que ganarlo.',
            'sets.1.b.different' => 'El set 2 no puede acabar en empate: alguien tiene que ganarlo.',
        ])->validate();

        // El set 3 solo cuenta si de verdad se jugó (algún juego/punto anotado).
        $tercerSetJugado = (int) ($validado['sets'][2]['a'] ?? 0) !== 0
            || (int) ($validado['sets'][2]['b'] ?? 0) !== 0;

        if ($tercerSetJugado && (int) $validado['sets'][2]['a'] === (int) $validado['sets'][2]['b']) {
            $this->addError('resultados.'.$partidoId.'.sets.2.b', 'El set 3 no puede acabar en empate: alguien tiene que ganarlo.');

            return;
        }

        // Si los dos primeros sets se los repartieron, hace falta un tercero.
        $ganaA1 = $validado['sets'][0]['a'] > $validado['sets'][0]['b'];
        $ganaA2 = $validado['sets'][1]['a'] > $validado['sets'][1]['b'];
        if ($ganaA1 !== $ganaA2 && ! $tercerSetJugado) {
            $this->addError('resultados.'.$partidoId.'.sets.2.a', 'Los dos primeros sets están repartidos: falta el resultado del tercero.');

            return;
        }

        $detalleSets = [
            ['a' => (int) $validado['sets'][0]['a'], 'b' => (int) $validado['sets'][0]['b']],
            ['a' => (int) $validado['sets'][1]['a'], 'b' => (int) $validado['sets'][1]['b']],
        ];

        if ($tercerSetJugado) {
            $detalleSets[] = [
                'a'         => (int) $validado['sets'][2]['a'],
                'b'         => (int) $validado['sets'][2]['b'],
                'tie_break' => (bool) ($this->resultados[$partidoId]['sets'][2]['tie_break'] ?? true),
            ];
        }

        $eraNuevo = ! $partido->jugado();

        $partido->registrarResultado(
            $detalleSets,
            $validado['retirado_id'] ? (int) $validado['retirado_id'] : null,
        );

        $partido->refresh()->load('jugadores');

        Auditoria::registrar(
            'resultado.guardado',
            ($eraNuevo ? 'Resultado anotado' : 'Resultado corregido').': '
                .$partido->equipo('a')->pluck('nombre')->join(' + ').' '
                .collect($detalleSets)->map(fn ($s) => $s['a'].'-'.$s['b'])->join(' ')
                .' '.$partido->equipo('b')->pluck('nombre')->join(' + ')
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

        $marcador = collect($partido->detalle_sets ?: [])->map(fn ($s) => $s['a'].'-'.$s['b'])->join(' ')
            ?: $partido->sets_a.'-'.$partido->sets_b;

        $descripcion = $partido->equipo('a')->pluck('nombre')->join(' + ').' '.$marcador.' '
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

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
     * [partido_id => ['sets' => [['a'=>x,'b'=>y], ...], 'retirado_id' => z]]
     * 4 filas en el formulario: Set 1, Set 2, Set 3 y Súper Tie-Break.
     * Solo el Set 1 es obligatorio. El Súper Tie-Break es siempre opcional.
     */
    public array $resultados = [];

    public ?int $editando = null;

    public function abrir(int $partidoId): void
    {
        $partido = Partido::with('jugadores')->findOrFail($partidoId);

        Gate::authorize('registrar-resultado', $partido);

        // Estructura fija de 4 filas para el formulario: Set 1, Set 2, Set 3 y Súper Tie-Break
        $setsFormulario = [
            ['a' => 0, 'b' => 0],
            ['a' => 0, 'b' => 0],
            ['a' => 0, 'b' => 0],
            ['a' => 0, 'b' => 0, 'tie_break' => true],
        ];

        $detalleGuardado = $partido->detalle_sets ?: [];
        $indexRegular = 0;

        foreach ($detalleGuardado as $set) {
            if (! empty($set['tie_break'])) {
                $setsFormulario[3] = [
                    'a'         => (int) ($set['a'] ?? 0),
                    'b'         => (int) ($set['b'] ?? 0),
                    'tie_break' => true,
                ];
            } elseif ($indexRegular < 3) {
                $setsFormulario[$indexRegular] = [
                    'a' => (int) ($set['a'] ?? 0),
                    'b' => (int) ($set['b'] ?? 0),
                ];
                $indexRegular++;
            }
        }

        $this->editando = $partidoId;
        $this->resultados[$partidoId] = [
            'sets'        => $setsFormulario,
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

        if ($partido->jugado()) {
            $this->authorize('editar-resultado');
        } else {
            $this->authorize('registrar-resultado', $partido);
        }

        $datos = $this->resultados[$partidoId] ?? null;
        if (! $datos) {
            return;
        }

        $datos['retirado_id'] = $datos['retirado_id'] !== '' ? $datos['retirado_id'] : null;

        $validado = validator($datos, [
            'sets'        => ['required', 'array', 'size:4'],
            'sets.0.a'    => ['required', 'integer', 'min:0', 'max:30'],
            'sets.0.b'    => ['required', 'integer', 'min:0', 'max:30', 'different:sets.0.a'],
            'sets.1.a'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.1.b'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.2.a'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.2.b'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.3.a'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'sets.3.b'    => ['nullable', 'integer', 'min:0', 'max:30'],
            'retirado_id' => ['nullable', 'integer', 'exists:jugadores,id'],
        ], [
            'sets.0.b.different' => 'El set 1 no puede acabar en empate: alguien tiene que ganarlo.',
        ])->validate();

        // Función auxiliar para comprobar si un set se ha jugado (puntos anotados)
        $esJugado = fn ($s) => ((int) ($s['a'] ?? 0)) > 0 || ((int) ($s['b'] ?? 0)) > 0;

        // Comprobación de empates en sets jugados (Sets 2, 3 y Súper Tie-Break)
        for ($i = 1; $i <= 3; $i++) {
            if ($esJugado($validado['sets'][$i])) {
                $a = (int) $validado['sets'][$i]['a'];
                $b = (int) $validado['sets'][$i]['b'];
                if ($a === $b) {
                    $nombre = $i === 3 ? 'El Súper Tie-Break' : 'El set '.($i + 1);
                    $this->addError('resultados.'.$partidoId.'.sets.'.$i.'.b', "{$nombre} no puede acabar en empate.");

                    return;
                }
            }
        }

        // Construir el array final filtrando solo los sets disputados
        $detalleSets = [];
        for ($i = 0; $i < 4; $i++) {
            if ($i === 0 || $esJugado($validado['sets'][$i])) {
                $item = [
                    'a' => (int) $validado['sets'][$i]['a'],
                    'b' => (int) $validado['sets'][$i]['b'],
                ];
                if ($i === 3) {
                    $item['tie_break'] = true;
                }
                $detalleSets[] = $item;
            }
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

        $this->authorize('editar-resultado');

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

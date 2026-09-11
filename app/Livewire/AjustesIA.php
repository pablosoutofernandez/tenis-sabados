<?php

namespace App\Livewire;

use App\Models\AjustesIA as ConfigIA;
use App\Models\Auditoria;
use App\Models\Jornada;
use App\Services\HistorialTenis;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AjustesIA extends Component
{
    public int $prioridadEquilibrio  = 3;
    public int $prioridadNoRepetir   = 3;
    public int $prioridadFrenarLider = 3;

    public function mount(): void
    {
        Gate::authorize('gestionar-ajustes');

        $actuales = ConfigIA::actuales();

        $this->prioridadEquilibrio  = $actuales->prioridad_equilibrio;
        $this->prioridadNoRepetir   = $actuales->prioridad_no_repetir;
        $this->prioridadFrenarLider = $actuales->prioridad_frenar_lider;
    }

    /** Preajustes rápidos: solo mueven los sliders, hay que guardar aparte. */
    public function preset(string $nombre): void
    {
        [$this->prioridadNoRepetir, $this->prioridadEquilibrio, $this->prioridadFrenarLider] = match ($nombre) {
            'variedad'   => [5, 3, 3],
            'equilibrio' => [3, 5, 3],
            'competitivo' => [3, 3, 5],
            default      => [3, 3, 3], // equilibrado
        };
    }

    public function guardar(): void
    {
        $datos = $this->validate([
            'prioridadEquilibrio'  => ['required', 'integer', 'between:1,5'],
            'prioridadNoRepetir'   => ['required', 'integer', 'between:1,5'],
            'prioridadFrenarLider' => ['required', 'integer', 'between:1,5'],
        ]);

        ConfigIA::actuales()->update([
            'prioridad_equilibrio'   => $datos['prioridadEquilibrio'],
            'prioridad_no_repetir'   => $datos['prioridadNoRepetir'],
            'prioridad_frenar_lider' => $datos['prioridadFrenarLider'],
        ]);

        Auditoria::registrar(
            'ajustes.guardados',
            'Pesos del algoritmo: variedad '.$datos['prioridadNoRepetir']
                .', equilibrio '.$datos['prioridadEquilibrio']
                .', frenar al líder '.$datos['prioridadFrenarLider'].'.',
        );

        session()->flash('success', 'Ajustes guardados. Se aplican en la próxima jornada que generes.');
    }

    public function render(HistorialTenis $historial)
    {
        $anio = (int) now()->year;

        [$companeros] = $historial->coincidenciasCrudas($anio);

        $clavesUnicas = collect($companeros)
            ->flatMap(fn ($otros, $id) => collect(array_keys($otros))
                ->map(fn ($otroId) => collect([$id, $otroId])->sort()->implode('-')))
            ->unique();

        return view('livewire.ajustes-ia', [
            'jornadasJugadas'  => Jornada::whereYear('fecha', $anio)->count(),
            'parejasDistintas' => $clavesUnicas->count(),
            'parejaMasVista'   => collect($companeros)->flatten()->max() ?? 0,
        ]);
    }
}

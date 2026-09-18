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
    public int $prioridadEquilibrio      = 3;
    public int $prioridadNoRepetirParejas = 3;
    public int $prioridadNoRepetirRivales = 3;
    public int $prioridadFrenarLider     = 3;

    public function mount(): void
    {
        Gate::authorize('gestionar-ajustes');

        $actuales = ConfigIA::actuales();

        $this->prioridadEquilibrio       = $actuales->prioridad_equilibrio;
        $this->prioridadNoRepetirParejas = $actuales->prioridad_no_repetir_parejas;
        $this->prioridadNoRepetirRivales = $actuales->prioridad_no_repetir_rivales;
        $this->prioridadFrenarLider      = $actuales->prioridad_frenar_lider;
    }

    /** Preajustes rápidos: solo mueven los sliders, hay que guardar aparte. */
    public function preset(string $nombre): void
    {
        [
            $this->prioridadNoRepetirParejas,
            $this->prioridadNoRepetirRivales,
            $this->prioridadEquilibrio,
            $this->prioridadFrenarLider,
        ] = match ($nombre) {
            'variedad'    => [5, 5, 3, 3],
            'cruces'      => [3, 5, 3, 3],
            'equilibrio'  => [3, 3, 5, 3],
            'competitivo' => [3, 3, 3, 5],
            default       => [3, 3, 3, 3], // equilibrado
        };
    }

    public function guardar(): void
    {
        $datos = $this->validate([
            'prioridadEquilibrio'       => ['required', 'integer', 'between:1,5'],
            'prioridadNoRepetirParejas' => ['required', 'integer', 'between:1,5'],
            'prioridadNoRepetirRivales' => ['required', 'integer', 'between:1,5'],
            'prioridadFrenarLider'      => ['required', 'integer', 'between:1,5'],
        ]);

        ConfigIA::actuales()->update([
            'prioridad_equilibrio'         => $datos['prioridadEquilibrio'],
            'prioridad_no_repetir_parejas' => $datos['prioridadNoRepetirParejas'],
            'prioridad_no_repetir_rivales' => $datos['prioridadNoRepetirRivales'],
            'prioridad_frenar_lider'       => $datos['prioridadFrenarLider'],
        ]);

        Auditoria::registrar(
            'ajustes.guardados',
            'Pesos del algoritmo: no repetir parejas '.$datos['prioridadNoRepetirParejas']
                .', no repetir rivales '.$datos['prioridadNoRepetirRivales']
                .', equilibrio '.$datos['prioridadEquilibrio']
                .', frenar al líder '.$datos['prioridadFrenarLider'].'.',
        );

        session()->flash('success', 'Ajustes guardados. Se aplican en la próxima jornada que generes.');
    }

    public function render(HistorialTenis $historial)
    {
        $anio = (int) now()->year;

        [$companeros, $rivales] = $historial->coincidenciasCrudas($anio);

        return view('livewire.ajustes-ia', [
            'jornadasJugadas'  => Jornada::whereYear('fecha', $anio)->count(),
            'parejasDistintas' => $this->clavesUnicas($companeros)->count(),
            'parejaMasVista'   => collect($companeros)->flatten()->max() ?? 0,
            'crucesDistintos'  => $this->clavesUnicas($rivales)->count(),
            'cruceMasVisto'    => collect($rivales)->flatten()->max() ?? 0,
        ]);
    }

    /** Cuenta cada dúo una sola vez, no una por cada sentido de la matriz. */
    private function clavesUnicas(array $matriz)
    {
        return collect($matriz)
            ->flatMap(fn ($otros, $id) => collect(array_keys($otros))
                ->map(fn ($otroId) => collect([$id, $otroId])->sort()->implode('-')))
            ->unique();
    }
}

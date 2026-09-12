<?php

namespace App\Livewire;

use App\Models\Auditoria;
use App\Models\Jugador;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Jugadores extends Component
{
    use WithPagination;

    public string $buscar = '';
    public bool   $soloActivos = false;

    // Formulario
    public bool    $formVisible = false;
    public ?int    $jugadorId   = null;
    public string  $nombre      = '';
    public float   $nivel       = 5.0;
    public bool    $activo      = true;
    public bool    $es_refuerzo = false;

    public ?int $confirmandoId = null;

    public function mount(): void
    {
        Gate::authorize('gestionar-jugadores');
    }

    protected function reglas(): array
    {
        return [
            'nombre'      => ['required', 'string', 'min:2', 'max:60'],
            // Sin tope superior a propósito: el nivel lo mueve el sistema de
            // Elo tras cada resultado y no debe quedarse encajonado en 10.
            'nivel'       => ['required', 'numeric', 'min:1'],
            'activo'      => ['boolean'],
            'es_refuerzo' => ['boolean'],
        ];
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['buscar', 'soloActivos'], true)) {
            $this->resetPage();

            return;
        }

        if (isset($this->reglas()[$prop])) {
            $this->validateOnly($prop, $this->reglas());
        }
    }

    public function nuevo(): void
    {
        $this->resetForm();
        $this->formVisible = true;
    }

    public function editar(int $id): void
    {
        $jugador = Jugador::findOrFail($id);

        $this->jugadorId   = $jugador->id;
        $this->nombre      = $jugador->nombre;
        $this->nivel       = $jugador->nivel;
        $this->activo      = $jugador->activo;
        $this->es_refuerzo = $jugador->es_refuerzo;
        $this->formVisible = true;
    }

    public function guardar(): void
    {
        $datos = $this->validate($this->reglas());

        if ($this->jugadorId) {
            $jugador  = Jugador::findOrFail($this->jugadorId);
            $anterior = $jugador->nivel;
            $jugador->update($datos);

            $cambioNivel = abs($anterior - (float) $datos['nivel']) > 0.001
                ? ' (nivel '.number_format($anterior, 2).' → '.number_format((float) $datos['nivel'], 2).')'
                : '';

            Auditoria::registrar('jugador.editado', $datos['nombre'].' editado'.$cambioNivel.'.');
            session()->flash('success', $datos['nombre'].' actualizado.');
        } else {
            Jugador::create($datos);

            Auditoria::registrar('jugador.creado', $datos['nombre'].' añadido al torneo con nivel '.number_format((float) $datos['nivel'], 2).'.');
            session()->flash('success', $datos['nombre'].' añadido al torneo.');
        }

        $this->resetForm();
    }

    public function cancelar(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->jugadorId   = null;
        $this->nombre      = '';
        $this->nivel       = 5.0;
        $this->activo      = true;
        $this->es_refuerzo = false;
        $this->formVisible = false;
        $this->resetValidation();
    }

    public function pedirConfirmacion(int $id): void
    {
        $this->confirmandoId = $id;
    }

    public function cancelarConfirmacion(): void
    {
        $this->confirmandoId = null;
    }

    public function eliminar(int $id): void
    {
        $jugador = Jugador::findOrFail($id);
        $nombre  = $jugador->nombre;
        $jugador->delete();

        $this->confirmandoId = null;

        Auditoria::registrar('jugador.eliminado', $nombre.' eliminado del torneo.');
        session()->flash('success', 'Has eliminado a '.$nombre.'. Sus partidos y puntos se han borrado con él.');
    }

    public function render()
    {
        $puntos = Jugador::puntosPorJugador((int) now()->year);

        $jugadores = Jugador::query()
            ->buscar($this->buscar)
            ->when($this->soloActivos, fn ($q) => $q->activos())
            ->orderBy('nombre')
            ->paginate(15);

        return view('livewire.jugadores', [
            'jugadores' => $jugadores,
            'puntos'    => $puntos,
        ]);
    }
}

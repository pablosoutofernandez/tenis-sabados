<?php

namespace App\Livewire;

use App\Models\Auditoria;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use App\Services\EmparejadorIA;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class GenerarJornada extends Component
{
    public string $fecha = '';
    public array  $disponibles = [];       // ids marcados
    public ?int   $jornadaId = null;
    public ?string $error = null;
    public array  $avisos = [];            // repeticiones o desequilibrios que quedaron

    // Corregir a mano un partido concreto: solo cambia quién va con quién.
    public ?int  $editandoPartidoId = null;
    public array $equiposEdicion    = [];  // [jugador_id => 'a'|'b']

    public function mount(?int $jornadaId = null): void
    {
        Gate::authorize('gestionar-jornadas');

        $this->fecha = Carbon::now()->next(Carbon::SATURDAY)->toDateString();

        if ($jornadaId) {
            $this->cargar(Jornada::with('jugadores')->findOrFail($jornadaId));
        } else {
            // Refuerzos empiezan desmarcados: se apuntan a mano cuando hacen falta.
            $this->disponibles = Jugador::activos()->where('es_refuerzo', false)->pluck('id')->all();
            $this->buscarJornadaDeLaFecha();
        }
    }

    public function updatedFecha(): void
    {
        $this->error  = null;
        $this->avisos = [];
        $this->buscarJornadaDeLaFecha();
    }

    private function buscarJornadaDeLaFecha(): void
    {
        $jornada = Jornada::with('jugadores')->whereDate('fecha', $this->fecha)->first();

        if ($jornada) {
            $this->cargar($jornada);
        } else {
            $this->jornadaId = null;
        }
    }

    private function cargar(Jornada $jornada): void
    {
        $this->jornadaId   = $jornada->id;
        $this->fecha       = $jornada->fecha->toDateString();
        $this->disponibles = $jornada->jugadores->pluck('id')->all();
    }

    public function alternar(int $id): void
    {
        $this->disponibles = in_array($id, $this->disponibles, true)
            ? array_values(array_diff($this->disponibles, [$id]))
            : [...$this->disponibles, $id];
    }

    public function marcarTodos(): void
    {
        $this->disponibles = Jugador::activos()->pluck('id')->all();
    }

    public function vaciar(): void
    {
        $this->disponibles = [];
    }

    /** Pistas que salen hoy: como mucho 3, siempre de 4 en 4. */
    public function getPistasProperty(): int
    {
        $porPista = (int) config('tenis.jugadores_por_pista', 4);

        return min((int) config('tenis.pistas_max', 3), intdiv(count($this->disponibles), $porPista));
    }

    /** Disponibles que sobran porque no dan para otra pista. */
    public function getSobranProperty(): int
    {
        return count($this->disponibles) - ($this->pistas * (int) config('tenis.jugadores_por_pista', 4));
    }

    public function generar(EmparejadorIA $ia): void
    {
        $this->authorize('gestionar-jornadas');

        $this->error  = null;
        $this->avisos = [];

        // Buscar si ya existe la jornada para esta fecha
        $jornadaExistente = Jornada::whereDate('fecha', $this->fecha)->first();

        // Si la jornada existe y ya está publicada, bloqueamos la regeneración
        if ($jornadaExistente && $jornadaExistente->estado === 'publicada') {
            $this->error = 'No se puede regenerar una jornada que ya ha sido publicada.';
            return;
        }

        if ($this->pistas < 1) {
            $this->error = 'Marca al menos 4 jugadores disponibles para poder montar una pista.';
            return;
        }

        $jornada = $jornadaExistente ?? new Jornada(['fecha' => $this->fecha]);

        $jornada->fill(['pistas' => $this->pistas, 'estado' => 'borrador'])->save();

        $jornada->jugadores()->sync(
            collect($this->disponibles)->mapWithKeys(fn ($id) => [$id => ['fuera' => false]])->all()
        );

        try {
            $propuesta = $ia->proponer($jornada, $this->disponibles);

            // Los avisos se calculan ANTES de guardar: si no, la propia jornada
            // nueva contaría como repetición de sí misma.
            $this->avisos = $ia->avisos($propuesta, (int) $jornada->fecha->year);

            $ia->aplicar($jornada, $propuesta);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->jornadaId = $jornada->id;

        Auditoria::registrar(
            'jornada.generada',
            'Jornada del '.$jornada->fecha->format('d/m/Y').' repartida en '.count($propuesta['partidos']).' pistas.',
            $jornada->id,
        );

        session()->flash('success', 'Jornada del '.$jornada->fecha->translatedFormat('j \d\e F').' montada.');
    }

    public function publicar(): void
    {
        $this->authorize('gestionar-jornadas');

        $jornada = Jornada::findOrFail($this->jornadaId);
        $jornada->update(['estado' => 'publicada']);

        Auditoria::registrar('jornada.publicada', 'Jornada del '.$jornada->fecha->format('d/m/Y').' publicada.', $jornada->id);
        session()->flash('success', 'Jornada publicada. Ya puedes avisar a los jugadores.');
    }

    public function eliminarJornada(): void
    {
        $this->authorize('gestionar-jornadas');

        $jornada = Jornada::find($this->jornadaId);

        if ($jornada) {
            // Si la jornada ya está publicada, impedimos su eliminación
            if ($jornada->estado === 'publicada') {
                $this->error = 'No se puede eliminar una jornada que ya ha sido publicada.';
                return;
            }

            $fecha = $jornada->fecha->format('d/m/Y');
            $jornada->delete();

            Auditoria::registrar('jornada.eliminada', 'Jornada del '.$fecha.' eliminada con sus partidos y resultados.');
        }

        $this->jornadaId = null;
        $this->avisos    = [];
        session()->flash('success', 'Jornada eliminada.');
    }

    /** Abre el modo de edición de un partido: solo si aún no tiene resultado y la jornada es borrador. */
    public function editarPartido(int $partidoId): void
    {
        $this->authorize('gestionar-jornadas');

        $partido = Partido::with(['jugadores', 'jornada'])->findOrFail($partidoId);

        if ($partido->jornada->estado === 'publicada') {
            $this->error = 'No se pueden modificar los emparejamientos de una jornada ya publicada.';
            return;
        }

        if ($partido->jugado()) {
            $this->error = 'Este partido ya tiene resultado; borra el resultado en Partidos antes de tocar las parejas.';
            return;
        }

        $this->error             = null;
        $this->editandoPartidoId = $partidoId;
        $this->equiposEdicion    = $partido->jugadores
            ->mapWithKeys(fn (Jugador $j) => [$j->id => $j->pivot->equipo])
            ->all();
    }

    /** Cambia a un jugador de pareja dentro del mismo partido en edición. */
    public function alternarEquipo(int $jugadorId): void
    {
        $actual = $this->equiposEdicion[$jugadorId] ?? 'a';
        $this->equiposEdicion[$jugadorId] = $actual === 'a' ? 'b' : 'a';
    }

    public function cancelarEdicionPartido(): void
    {
        $this->editandoPartidoId = null;
        $this->equiposEdicion    = [];
    }

    /** Guarda el cambio de parejas de este partido concreto. */
    public function guardarEdicionPartido(): void
    {
        $this->authorize('gestionar-jornadas');
        $this->error = null;

        $partido = Partido::with('jornada')->findOrFail($this->editandoPartidoId);

        if ($partido->jornada->estado === 'publicada') {
            $this->error = 'No se pueden modificar los emparejamientos de una jornada ya publicada.';
            $this->cancelarEdicionPartido();
            return;
        }

        $enA = collect($this->equiposEdicion)->filter(fn ($e) => $e === 'a')->count();
        $enB = collect($this->equiposEdicion)->filter(fn ($e) => $e === 'b')->count();

        if ($enA !== 2 || $enB !== 2) {
            $this->error = 'Cada pareja necesita exactamente 2 jugadores.';
            return;
        }

        foreach ($this->equiposEdicion as $jugadorId => $equipo) {
            $partido->jugadores()->updateExistingPivot($jugadorId, ['equipo' => $equipo]);
        }

        $partido->load('jugadores');
        $parejaA = $partido->equipo('a')->pluck('nombre')->join(' + ');
        $parejaB = $partido->equipo('b')->pluck('nombre')->join(' + ');

        Auditoria::registrar(
            'partido.corregido',
            'Pista '.$partido->pista.' de la jornada del '.$partido->jornada->fecha->format('d/m/Y')
            .' cambiada a mano: '.$parejaA.' vs '.$parejaB.'.',
            $partido->jornada_id,
        );

        $this->editandoPartidoId = null;
        $this->equiposEdicion    = [];
        session()->flash('success', 'Partido corregido.');
    }

    public function render()
    {
        $jornada = $this->jornadaId
            ? Jornada::with(['partidos.jugadores', 'sinPista'])->find($this->jornadaId)
            : null;

        return view('livewire.generar-jornada', [
            'jugadores' => Jugador::activos()->orderBy('nombre')->get(),
            'jornada'   => $jornada,
        ]);
    }
}

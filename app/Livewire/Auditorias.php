<?php

namespace App\Livewire;

use App\Models\Auditoria;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Auditorias extends Component
{
    use WithPagination;

    public string $filtro = 'todo';

    public function mount(): void
    {
        Gate::authorize('ver-auditoria');
    }

    public function updatedFiltro(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $registros = Auditoria::query()
            ->when($this->filtro !== 'todo', fn ($q) => $q->where('accion', 'like', $this->filtro.'%'))
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('livewire.auditorias', [
            'registros' => $registros,
        ]);
    }
}

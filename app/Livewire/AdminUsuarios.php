<?php

namespace App\Livewire;

use App\Models\Auditoria;
use App\Models\Jugador;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class AdminUsuarios extends Component
{
    use WithPagination;

    public string $buscar = '';
    public bool   $soloPendientes = false;

    public ?int    $confirmandoId = null;
    public ?string $passwordTemporal = null;   // se enseña una sola vez tras resetear

    public function mount(): void
    {
        Gate::authorize('gestionar-usuarios');
    }

    public function updated(string $prop): void
    {
        if (in_array($prop, ['buscar', 'soloPendientes'], true)) {
            $this->resetPage();
        }
    }

    public function cambiarRol(int $userId, string $rol): void
    {
        if (! array_key_exists($rol, User::ROLES)) {
            return;
        }

        $usuario = User::findOrFail($userId);

        if ($this->esYo($usuario)) {
            session()->flash('error', 'No puedes cambiarte el rol a ti mismo.');

            return;
        }

        $usuario->update(['rol' => $rol]);

        Auditoria::registrar('usuario.actualizado', $usuario->name.' pasa a rol '.User::ROLES[$rol].'.');
        session()->flash('success', $usuario->name.' ahora es '.User::ROLES[$rol].'.');
    }

    public function vincularJugador(int $userId, ?string $jugadorId): void
    {
        $usuario = User::findOrFail($userId);
        $id      = $jugadorId !== '' && $jugadorId !== null ? (int) $jugadorId : null;

        if ($id !== null) {
            // Un jugador del torneo no puede estar vinculado a dos cuentas.
            $ocupado = User::where('jugador_id', $id)->where('id', '!=', $userId)->first();

            if ($ocupado) {
                session()->flash('error', 'Ese jugador ya está vinculado a la cuenta de '.$ocupado->name.'.');

                return;
            }
        }

        $usuario->update(['jugador_id' => $id]);

        $nombre = $id ? Jugador::find($id)?->nombre : null;

        Auditoria::registrar(
            'usuario.actualizado',
            $nombre
                ? $usuario->name.' vinculado al jugador '.$nombre.'.'
                : $usuario->name.' desvinculado de su jugador.',
        );

        session()->flash('success', $nombre ? $usuario->name.' → '.$nombre : 'Vínculo eliminado.');
    }

    public function alternarActivo(int $userId): void
    {
        $usuario = User::findOrFail($userId);

        if ($this->esYo($usuario)) {
            session()->flash('error', 'No puedes desactivar tu propia cuenta.');

            return;
        }

        $usuario->update(['activo' => ! $usuario->activo]);

        Auditoria::registrar(
            'usuario.actualizado',
            $usuario->name.($usuario->activo ? ' activado.' : ' desactivado.'),
        );

        session()->flash('success', $usuario->name.($usuario->activo ? ' activado.' : ' desactivado.'));
    }

    /** Genera una contraseña temporal y obliga a cambiarla al entrar. */
    public function resetearPassword(int $userId): void
    {
        $usuario = User::findOrFail($userId);

        $temporal = Str::password(12, symbols: false);

        $usuario->update([
            'password'              => $temporal,
            'debe_cambiar_password' => true,
        ]);

        $this->passwordTemporal = $usuario->name.': '.$temporal;

        Auditoria::registrar('usuario.actualizado', 'Contraseña de '.$usuario->name.' reseteada.');
    }

    public function ocultarPassword(): void
    {
        $this->passwordTemporal = null;
    }

    public function pedirConfirmacion(int $id): void
    {
        $this->confirmandoId = $id;
    }

    public function cancelarConfirmacion(): void
    {
        $this->confirmandoId = null;
    }

    public function eliminar(int $userId): void
    {
        $usuario = User::findOrFail($userId);

        if ($this->esYo($usuario)) {
            session()->flash('error', 'No puedes eliminar tu propia cuenta.');

            return;
        }

        $nombre = $usuario->name;
        $usuario->delete();

        $this->confirmandoId = null;

        Auditoria::registrar('usuario.eliminado', 'Cuenta de '.$nombre.' eliminada.');
        session()->flash('success', 'Cuenta de '.$nombre.' eliminada.');
    }

    private function esYo(User $usuario): bool
    {
        return $usuario->id === Auth::id();
    }

    public function render()
    {
        $usuarios = User::query()
            ->with('jugador')
            ->when($this->buscar, fn ($q) => $q->where('name', 'like', '%'.$this->buscar.'%'))
            ->when($this->soloPendientes, fn ($q) => $q->where('activo', false))
            ->orderByDesc('activo')
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.admin-usuarios', [
            'usuarios'   => $usuarios,
            'jugadores'  => Jugador::orderBy('nombre')->get(['id', 'nombre']),
            'pendientes' => User::where('activo', false)->count(),
        ]);
    }
}

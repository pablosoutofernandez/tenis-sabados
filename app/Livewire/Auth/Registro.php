<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Registro extends Component
{
    public string $name = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $registrado = false;

    public function registrarse(): void
    {
        $datos = $this->validate([
            'name'     => ['required', 'string', 'min:2', 'max:60', 'unique:users,name'],
            'password' => ['required', 'confirmed', 'string', 'min:4'],
        ]);

        // Entra como jugador e inactivo: el admin aprueba la cuenta y la
        // vincula con el jugador del torneo que corresponda.
        User::create([
            'name'     => $datos['name'],
            'password' => $datos['password'],
            'rol'      => User::ROL_JUGADOR,
            'activo'   => false,
        ]);

        $this->registrado = true;
    }

    public function render()
    {
        return view('livewire.auth.registro');
    }
}

<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    public string $name = '';
    public string $password = '';
    public bool   $recordarme = false;

    public function entrar(): void
    {
        $datos = $this->validate([
            'name'     => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($datos, $this->recordarme)) {
            throw ValidationException::withMessages([
                'name' => 'Ese nombre y contraseña no coinciden con ninguna cuenta.',
            ]);
        }

        if (! Auth::user()->activo) {
            Auth::logout();

            throw ValidationException::withMessages([
                'name' => 'Tu cuenta todavía no está activada. Habla con el administrador.',
            ]);
        }

        session()->regenerate();

        $this->redirect(
            Auth::user()->debe_cambiar_password
                ? route('password.cambiar')
                : route('inicio'),
            navigate: true,
        );
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}

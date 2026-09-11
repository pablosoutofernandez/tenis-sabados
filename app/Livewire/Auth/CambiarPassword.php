<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CambiarPassword extends Component
{
    public string $actual = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function guardar(): void
    {
        $datos = $this->validate([
            'actual'   => ['required', 'string'],
            'password' => ['required', 'confirmed', 'string', 'min:4'],
        ]);

        if (! Hash::check($datos['actual'], Auth::user()->password)) {
            throw ValidationException::withMessages([
                'actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        Auth::user()->update([
            'password'              => $datos['password'],
            'debe_cambiar_password' => false,
        ]);

        $this->reset('actual', 'password', 'password_confirmation');

        session()->flash('success', 'Contraseña actualizada.');
        $this->redirect(route('inicio'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.cambiar-password');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\GenerarJornada;
use App\Models\AjustesIA;
use App\Models\Jugador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DetalleCalculoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $rol): User
    {
        return User::factory()->create(['rol' => $rol, 'activo' => true]);
    }

    private function generarComo(User $usuario)
    {
        AjustesIA::actuales();
        foreach (range(1, 8) as $i) {
            Jugador::create(['nombre' => "J{$i}", 'nivel' => 3 + $i / 2, 'activo' => true]);
        }

        return Livewire::actingAs($usuario)
            ->test(GenerarJornada::class)
            ->call('generar')
            ->assertSet('error', null);
    }

    public function test_el_admin_ve_el_detalle_tecnico(): void
    {
        $this->generarComo($this->usuario(User::ROL_ADMIN))
            ->assertSee('Detalle técnico del cálculo')
            ->assertSee('Coste por pista')
            ->assertSee('Mejores alternativas');
    }

    public function test_el_organizador_no_lo_ve(): void
    {
        $this->generarComo($this->usuario(User::ROL_ORGANIZADOR))
            ->assertDontSee('Detalle técnico del cálculo');
    }
}

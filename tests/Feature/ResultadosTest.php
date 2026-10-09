<?php

namespace Tests\Feature;

use App\Livewire\HistorialPartidos;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Marcadores completos: puntos del torneo y ajuste de nivel. Todos entre
 * cuatro jugadores de nivel 5 en su primer partido (k provisional = 1,5
 * por set), así que delta = 0,75 × Σ (½ % juegos del set + ½ ganado − 0,5),
 * con el súper tie-break a medio peso.
 */
class ResultadosTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int> [a1, a2, b1, b2] */
    private array $ids;

    private function partido(): Partido
    {
        $jornada = Jornada::create(['fecha' => '2026-03-07', 'pistas' => 1, 'estado' => 'publicada']);
        $partido = Partido::create(['jornada_id' => $jornada->id, 'pista' => 1]);

        $this->ids = array_map(fn () => Jugador::create(['nombre' => uniqid(), 'nivel' => 5, 'activo' => true])->id, range(1, 4));
        [$a1, $a2, $b1, $b2] = $this->ids;
        $partido->jugadores()->attach([
            $a1 => ['equipo' => 'a', 'puntos' => 0], $a2 => ['equipo' => 'a', 'puntos' => 0],
            $b1 => ['equipo' => 'b', 'puntos' => 0], $b2 => ['equipo' => 'b', 'puntos' => 0],
        ]);

        return $partido->fresh('jugadores');
    }

    /** "6-4 4-6 st:10-5" → detalle_sets; "st:" marca el súper tie-break. */
    private function sets(string $marcador): array
    {
        return collect(explode(' ', $marcador))->map(function ($s) {
            $super = str_starts_with($s, 'st:');
            [$a, $b] = explode('-', ltrim($s, 'st:'));

            return ['a' => (int) $a, 'b' => (int) $b] + ($super ? ['tie_break' => true] : []);
        })->all();
    }

    private function pivote(Partido $partido, int $i): object
    {
        return $partido->fresh('jugadores')->jugadores->firstWhere('id', $this->ids[$i])->pivot;
    }

    public static function marcadores(): array
    {
        // marcador, sets A-B, puntos A, puntos B, delta pareja A
        return [
            '2-0 claro'                         => ['6-4 6-4',             2, 0, 2, 0,  0.45],
            '3-0 con los mismos juegos que 2-0' => ['6-4 6-4 6-4',         3, 0, 3, 0,  0.68],
            '2-1 en tres sets'                  => ['6-4 3-6 6-4',         2, 1, 2, 1,  0.20],
            '1-2 en tres sets'                  => ['4-6 6-3 4-6',         1, 2, 1, 2, -0.20],
            '2-1 con súper tie-break ganado'    => ['6-4 4-6 st:10-5',     2, 1, 2, 1,  0.13],
            '1-2 con súper tie-break perdido'   => ['6-4 4-6 st:7-10',     1, 2, 1, 2, -0.11],
            '2-1 ganando con menos juegos'      => ['7-6 7-6 0-6',         2, 1, 2, 1,  0.03],
            '3-1 con súper tie-break: tope 3'   => ['6-4 4-6 6-4 st:10-8', 3, 1, 3, 1,  0.33],
            // 3 sets + súper ganados: 4 sets, pero el torneo da como mucho 3
            // puntos. Para el nivel el súper sí cuenta (con medio peso).
            '4-0: 3-0 más súper ganado'         => ['6-4 6-4 6-4 st:10-5', 4, 0, 3, 0,  0.80],
            '0-4: 0-3 más súper perdido'        => ['4-6 4-6 4-6 st:5-10', 0, 4, 0, 3, -0.80],
            '2-2: 2-1 y súper perdido'          => ['6-4 4-6 6-4 st:8-10', 2, 2, 2, 2,  0.12],
        ];
    }

    #[DataProvider('marcadores')]
    public function test_puntos_y_nivel_segun_el_marcador(string $marcador, int $setsA, int $setsB, int $puntosA, int $puntosB, float $deltaA): void
    {
        $p = $this->partido();
        $p->registrarResultado($this->sets($marcador));
        $p->refresh();

        $this->assertSame([$setsA, $setsB], [$p->sets_a, $p->sets_b], 'sets');
        $this->assertSame($puntosA, (int) $this->pivote($p, 0)->puntos, 'puntos A');
        $this->assertSame($puntosB, (int) $this->pivote($p, 2)->puntos, 'puntos B');
        $this->assertEqualsWithDelta($deltaA, (float) $this->pivote($p, 0)->nivel_delta, 0.001, 'delta A');
        $this->assertEqualsWithDelta(-$deltaA, (float) $this->pivote($p, 2)->nivel_delta, 0.001, 'delta B');
        $this->assertSame(str_contains($marcador, 'st:'), $p->super_tie_break);
    }

    public function test_el_super_tie_break_pesa_la_mitad_que_un_set(): void
    {
        // Sorpresa por set: 6-4 → +0,3; 4-6 → −0,3; súper 10-5 → ½·0,667 + ½ − 0,5 = +0,333.
        // Con peso 0,5 el súper suma +0,167 → 0,75 × 0,167 = +0,13; con peso 1, +0,25.
        $p = $this->partido();
        $p->registrarResultado($this->sets('6-4 4-6 st:10-5'));

        $this->assertEqualsWithDelta(0.13, (float) $this->pivote($p, 0)->nivel_delta, 0.001);

        config(['tenis.elo.peso_super_tie_break' => 1.0]);
        $p->fresh()->registrarResultado($this->sets('6-4 4-6 st:10-5'));

        $this->assertEqualsWithDelta(0.25, (float) $this->pivote($p, 0)->nivel_delta, 0.001);
    }

    public function test_retirada_reparte_los_puntos_segun_las_normas(): void
    {
        // A gana el primer set y B se retira en el segundo.
        $p = $this->partido();
        $p->registrarResultado($this->sets('6-3 2-1'), $this->ids[2]);

        $this->assertSame(3, (int) $this->pivote($p, 0)->puntos, 'rival: los 3 puntos');
        $this->assertSame(0, (int) $this->pivote($p, 2)->puntos, 'retirado: conserva sus sets (0)');
        $this->assertSame(1, (int) $this->pivote($p, 3)->puntos, 'su compañero: 1 más');
        $this->assertEqualsWithDelta(0.0, (float) $this->pivote($p, 0)->nivel_delta, 0.001, 'no mueve nivel');
    }

    public function test_el_formulario_marca_el_cuarto_set_como_super_tie_break(): void
    {
        $p = $this->partido();
        $organizador = User::factory()->create(['rol' => User::ROL_ORGANIZADOR, 'activo' => true]);

        Livewire::actingAs($organizador)
            ->test(HistorialPartidos::class)
            ->call('abrir', $p->id)
            ->set("resultados.{$p->id}.sets", [
                ['a' => 6, 'b' => 4], ['a' => 4, 'b' => 6], ['a' => 0, 'b' => 0], ['a' => 10, 'b' => 5],
            ])
            ->call('guardarResultado', $p->id)
            ->assertHasNoErrors();

        $p->refresh();
        $this->assertSame([['a' => 6, 'b' => 4], ['a' => 4, 'b' => 6], ['a' => 10, 'b' => 5, 'tie_break' => true]], $p->detalle_sets);
        $this->assertSame([2, 1], [$p->sets_a, $p->sets_b]);
        $this->assertTrue($p->super_tie_break);
        $this->assertEqualsWithDelta(0.13, (float) $this->pivote($p, 0)->nivel_delta, 0.001);
    }
}

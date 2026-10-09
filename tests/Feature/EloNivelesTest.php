<?php

namespace Tests\Feature;

use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use App\Services\EloNiveles;
use App\Services\RecalculoNiveles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EloNivelesTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $fecha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fecha = Carbon::parse('2026-03-07');
    }

    /** @return array<int> ids */
    private function jugadores(array $niveles): array
    {
        return array_map(fn ($n) => Jugador::create(['nombre' => uniqid(), 'nivel' => $n, 'activo' => true])->id, $niveles);
    }

    /** Partido [a1, a2] contra [b1, b2] en la siguiente jornada libre. */
    private function partido(array $ids): Partido
    {
        $jornada = Jornada::create(['fecha' => $this->fecha->toDateString(), 'pistas' => 1, 'estado' => 'publicada']);
        $this->fecha->addWeek();

        $partido = Partido::create(['jornada_id' => $jornada->id, 'pista' => 1]);
        [$a1, $a2, $b1, $b2] = $ids;
        $partido->jugadores()->attach([
            $a1 => ['equipo' => 'a', 'puntos' => 0], $a2 => ['equipo' => 'a', 'puntos' => 0],
            $b1 => ['equipo' => 'b', 'puntos' => 0], $b2 => ['equipo' => 'b', 'puntos' => 0],
        ]);

        return $partido->fresh('jugadores');
    }

    private function deltaDe(Partido $partido, int $jugadorId): float
    {
        return (float) $partido->fresh('jugadores')->jugadores->firstWhere('id', $jugadorId)->pivot->nivel_delta;
    }

    private function sets(string $marcador): array
    {
        return collect(explode(' ', $marcador))->map(function ($s) {
            [$a, $b] = explode('-', $s);

            return ['a' => (int) $a, 'b' => (int) $b];
        })->all();
    }

    // ── Escala ──────────────────────────────────────────────────────────────

    public function test_lo_esperado_y_lo_real_se_miden_en_la_misma_mezcla(): void
    {
        $elo = app(EloNiveles::class);

        // +4 de suma: en cada set, ~72% de juegos y ~91% de ganarlo → 0,81.
        $this->assertEqualsWithDelta(0.5, $elo->esperado(0), 0.001);
        $this->assertEqualsWithDelta(0.81, $elo->esperado(4), 0.01);

        // Sorpresa entre iguales, sumada set a set: (½ % juegos + ½ ganado) − 0,5.
        $this->assertEqualsWithDelta(1.0, $elo->sorpresa($this->sets('6-0 6-0'), 0), 0.001);
        $this->assertEqualsWithDelta(0.538, $elo->sorpresa($this->sets('7-6 7-6'), 0), 0.001);
        $this->assertEqualsWithDelta(0.0, $elo->sorpresa($this->sets('6-4 4-6'), 0), 0.001);
        $this->assertEqualsWithDelta(2.0, $elo->sorpresa($this->sets('6-0 6-0 6-0 6-0'), 0), 0.001);
    }

    public function test_el_favorito_que_gana_comodo_no_baja(): void
    {
        // +2 de suma: se espera 61% de juegos y 76% de sets; 6-4 6-3 los supera.
        $ids = $this->jugadores([6, 6, 5, 5]);
        $p   = $this->partido($ids);
        $p->registrarResultado($this->sets('6-4 6-3'));

        $this->assertGreaterThan(0.0, $this->deltaDe($p, $ids[0]));
        $this->assertLessThan(0.0, $this->deltaDe($p, $ids[2]));
    }

    public function test_perder_siendo_favorito_baja(): void
    {
        $ids = $this->jugadores([6, 6, 5, 5]);
        $p   = $this->partido($ids);
        $p->registrarResultado($this->sets('4-6 4-6'));

        $this->assertLessThan(0.0, $this->deltaDe($p, $ids[0]));
        $this->assertGreaterThan(0.0, $this->deltaDe($p, $ids[2]));
    }

    // ── Magnitud: provisional y normal ──────────────────────────────────────

    public function test_en_periodo_provisional_se_mueve_rapido(): void
    {
        // Entre iguales, 6-4 6-4: cada set ½·0,6 + ½·1 − 0,5 = +0,3 → k × 0,6 / 2.
        $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);
        $p->registrarResultado($this->sets('6-4 6-4'));

        $this->assertSame(0.45, $this->deltaDe($p, $ids[0])); // k = 1,5
    }

    public function test_tras_8_partidos_pasa_al_k_normal(): void
    {
        [$veterano] = $ids = $this->jugadores([5, 5, 5, 5]);
        $relleno = $this->jugadores([5, 5, 5]);

        for ($i = 0; $i < 8; $i++) {
            $this->partido([$veterano, ...$relleno])->registrarResultado($this->sets('6-6 0-0'));
        }

        $p = $this->partido($ids);
        $p->registrarResultado($this->sets('6-4 6-4'));

        $this->assertSame(0.12, $this->deltaDe($p, $veterano));  // k = 0,4
        $this->assertSame(0.45, $this->deltaDe($p, $ids[1]));    // nuevo: k = 1,5
    }

    public function test_el_tope_por_partido_se_respeta(): void
    {
        // Muy favoritos que pierden 0-6 0-6: 0,75 × −1,9 pasaría de 1.
        $ids = $this->jugadores([8, 8, 3, 3]);
        $p   = $this->partido($ids);
        $p->registrarResultado($this->sets('0-6 0-6'));

        $this->assertSame(-1.0, $this->deltaDe($p, $ids[0]));
    }

    // ── Correcciones ────────────────────────────────────────────────────────

    public function test_corregir_un_resultado_no_acumula_el_ajuste_anterior(): void
    {
        [$a] = $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);

        $p->registrarResultado($this->sets('6-0 6-0'));
        $tras = Jugador::find($a)->nivel;
        $p->fresh()->registrarResultado($this->sets('6-0 6-0'));

        $this->assertEqualsWithDelta($tras, Jugador::find($a)->nivel, 0.001);
    }

    public function test_borrar_el_resultado_deja_el_nivel_como_estaba(): void
    {
        [$a] = $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);

        $p->registrarResultado($this->sets('6-1 6-2'));
        $p->fresh()->borrarResultado();

        $this->assertEqualsWithDelta(5.0, Jugador::find($a)->nivel, 0.001);
    }

    public function test_una_retirada_no_mueve_nivel(): void
    {
        $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);

        $p->registrarResultado($this->sets('6-2 1-0'), $ids[2]);

        $this->assertSame(0.0, $this->deltaDe($p, $ids[0]));
    }

    public function test_corregir_a_retirada_deshace_el_ajuste_previo(): void
    {
        [$a] = $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);

        $p->registrarResultado($this->sets('6-0 6-0'));
        $p->fresh()->registrarResultado($this->sets('6-0 1-0'), $ids[2]);

        $this->assertEqualsWithDelta(5.0, Jugador::find($a)->nivel, 0.001);
    }

    // ── Recálculo de temporada ──────────────────────────────────────────────

    public function test_recalcular_con_factor_1_coincide_con_el_elo_en_vivo(): void
    {
        $ids = $this->jugadores([6, 5.5, 5, 4]);
        $this->partido($ids)->registrarResultado($this->sets('6-3 6-4'));
        $this->partido([$ids[0], $ids[2], $ids[1], $ids[3]])->registrarResultado($this->sets('3-6 6-7'));
        $this->partido([$ids[0], $ids[3], $ids[1], $ids[2]])->registrarResultado($this->sets('6-2 1-0'), $ids[1]);

        $recalculo = app(RecalculoNiveles::class)->calcular(2026, 1.0);

        foreach ($recalculo['jugadores'] as $id => $j) {
            $this->assertEqualsWithDelta($j['actual'], $j['nuevo'], 0.001);
        }
        $this->assertSame(5.5, $recalculo['jugadores'][$ids[1]]['inicial']);
    }

    public function test_recalcular_aplica_el_elo_nuevo_mas_suave_que_en_vivo(): void
    {
        $ids = $this->jugadores([5, 5, 5, 5]);
        $p   = $this->partido($ids);
        $p->registrarResultado($this->sets('6-4 6-4'));

        // Simula un ajuste hecho con el Elo antiguo (+0,05). En vivo hoy sería
        // +0,45; el recálculo usa k × 0,6 → +0,27.
        Jugador::whereIn('id', [$ids[0], $ids[1]])->update(['nivel' => 5.05]);
        Jugador::whereIn('id', [$ids[2], $ids[3]])->update(['nivel' => 4.95]);
        \DB::table('partido_jugador')->where('partido_id', $p->id)->whereIn('jugador_id', [$ids[0], $ids[1]])->update(['nivel_delta' => 0.05]);
        \DB::table('partido_jugador')->where('partido_id', $p->id)->whereIn('jugador_id', [$ids[2], $ids[3]])->update(['nivel_delta' => -0.05]);

        $servicio = app(RecalculoNiveles::class);
        $servicio->guardar($servicio->calcular(2026));

        $this->assertEqualsWithDelta(5.27, Jugador::find($ids[0])->nivel, 0.001);
        $this->assertEqualsWithDelta(4.73, Jugador::find($ids[2])->nivel, 0.001);
        $this->assertSame(0.27, $this->deltaDe($p, $ids[0]));
    }
}

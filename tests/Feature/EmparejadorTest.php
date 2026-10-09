<?php

namespace Tests\Feature;

use App\Models\AjustesIA;
use App\Models\Jornada;
use App\Models\Jugador;
use App\Models\Partido;
use App\Services\Emparejamiento\AvisosJornada;
use App\Services\Emparejamiento\Emparejador;
use App\Services\Emparejamiento\ModeloCoste;
use App\Services\Emparejamiento\NivelEfectivo;
use App\Services\Emparejamiento\Pesos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmparejadorTest extends TestCase
{
    use RefreshDatabase;

    private Emparejador $emparejador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emparejador = app(Emparejador::class);
        AjustesIA::actuales();
    }

    // ── Ayudantes ───────────────────────────────────────────────────────────

    /** @return array<int> ids, en el orden de los niveles dados */
    private function jugadores(array $niveles, bool $refuerzo = false): array
    {
        static $n = 0;

        return collect($niveles)->values()->map(fn ($nivel) => Jugador::create([
            'nombre'      => 'J'.(++$n),
            'nivel'       => $nivel,
            'activo'      => true,
            'es_refuerzo' => $refuerzo,
        ])->id)->all();
    }

    /**
     * Jornada ya jugada. Cada pista es [a1, a2, b1, b2] y gana la pareja A
     * 2-1, así que A suma 2 puntos y B 1.
     */
    private function jornadaJugada(string $fecha, array $pistas): Jornada
    {
        $jornada = Jornada::create(['fecha' => $fecha, 'pistas' => count($pistas), 'estado' => 'publicada']);

        foreach ($pistas as $i => [$a1, $a2, $b1, $b2]) {
            $partido = Partido::create(['jornada_id' => $jornada->id, 'pista' => $i + 1, 'sets_a' => 2, 'sets_b' => 1]);
            $partido->jugadores()->attach([
                $a1 => ['equipo' => 'a', 'puntos' => 2],
                $a2 => ['equipo' => 'a', 'puntos' => 2],
                $b1 => ['equipo' => 'b', 'puntos' => 1],
                $b2 => ['equipo' => 'b', 'puntos' => 1],
            ]);
        }

        return $jornada;
    }

    private function jornadaNueva(string $fecha = '2026-06-27'): Jornada
    {
        return Jornada::whereDate('fecha', $fecha)->first()
            ?? Jornada::create(['fecha' => $fecha, 'pistas' => 2, 'estado' => 'borrador']);
    }

    private function ajustes(int $parejas = 3, int $rivales = 3, int $equilibrio = 3, int $lider = 3): void
    {
        AjustesIA::actuales()->update([
            'prioridad_no_repetir_parejas' => $parejas,
            'prioridad_no_repetir_rivales' => $rivales,
            'prioridad_equilibrio'         => $equilibrio,
            'prioridad_frenar_lider'       => $lider,
        ]);
    }

    private function puntosManuales(int $jugadorId, int $puntos): void
    {
        $actuales = (int) \DB::table('ajustes_puntos')->where(['jugador_id' => $jugadorId, 'anio' => 2026])->value('puntos');

        \DB::table('ajustes_puntos')->updateOrInsert(
            ['jugador_id' => $jugadorId, 'anio' => 2026],
            ['puntos' => $actuales + $puntos],
        );
    }

    /** @return array<string> parejas "id-id" de una propuesta */
    private function parejas(array $propuesta): array
    {
        return collect($propuesta['partidos'])
            ->flatMap(fn ($p) => [$p['equipo_a'], $p['equipo_b']])
            ->map(fn ($par) => collect($par)->sort()->implode('-'))
            ->all();
    }

    private function companeroDe(int $id, array $propuesta): ?int
    {
        foreach ($propuesta['partidos'] as $p) {
            foreach ([$p['equipo_a'], $p['equipo_b']] as [$x, $y]) {
                if ($x === $id) return $y;
                if ($y === $id) return $x;
            }
        }

        return null;
    }

    // ── Estructura ──────────────────────────────────────────────────────────

    public static function tamanos(): array
    {
        return ['8' => [8, 2, 0], '12' => [12, 3, 0], '13' => [13, 3, 1], '15' => [15, 3, 3]];
    }

    #[DataProvider('tamanos')]
    public function test_reparte_a_todos_en_pistas_validas(int $n, int $pistas, int $fuera): void
    {
        $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $this->jugadores(array_fill(0, $n, 5.0)));

        $this->assertCount($pistas, $propuesta['partidos']);
        $this->assertCount($fuera, $propuesta['no_juegan']);
    }

    // ── Comportamiento básico ───────────────────────────────────────────────

    public function test_sin_historial_cuadra_el_nivel(): void
    {
        $ids = $this->jugadores([8, 7, 6, 5.5, 5, 4.5, 4, 3]);

        $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $ids);

        foreach ($propuesta['calculo']['pistas'] as $pista) {
            $this->assertSame(0.0, $pista['equilibrio']['diferencia']);
        }
    }

    public function test_no_repite_pareja_de_la_semana_pasada_si_hay_alternativa(): void
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = $ids = $this->jugadores(array_fill(0, 8, 5.0));
        $this->jornadaJugada('2026-06-20', [[$a, $b, $c, $d], [$e, $f, $g, $h]]);

        for ($i = 0; $i < 10; $i++) {
            $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $ids);

            $this->assertEmpty(array_intersect($this->parejas($propuesta), ["$a-$b", "$c-$d", "$e-$f", "$g-$h"]));
        }
    }

    public function test_el_desglose_suma_exactamente_el_coste_elegido(): void
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = $ids = $this->jugadores([8, 7, 6, 6, 5, 5, 4, 3]);
        $this->jornadaJugada('2026-06-13', [[$a, $h, $b, $g], [$c, $f, $d, $e]]);
        $this->jornadaJugada('2026-06-20', [[$a, $g, $c, $e], [$b, $h, $d, $f]]);

        $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $ids);
        $calculo   = $propuesta['calculo'];

        $this->assertEqualsWithDelta($propuesta['coste'], array_sum(array_column($calculo['pistas'], 'total')), 1e-9);
        $this->assertEqualsWithDelta($propuesta['coste'], $calculo['alternativas'][0]['coste'], Pesos::TOLERANCIA_EMPATE);
        $this->assertSame(35, $calculo['busqueda']['evaluados']); // 8 jugadores: 35 repartos
    }

    // ── Escalas ─────────────────────────────────────────────────────────────

    public function test_rival_de_hace_dos_semanas_pesa_lo_documentado(): void
    {
        foreach ([3 => 0.06, 5 => 0.24] as $prioridad => $esperado) {
            $this->ajustes(rivales: $prioridad);
            $pesos = Pesos::desde(AjustesIA::actuales());

            $this->assertEqualsWithDelta($esperado, ModeloCoste::pesoRecencia(2, $pesos->decayRival, 0.0), 0.001);
        }
    }

    public function test_repetir_pareja_de_la_semana_pasada_equivale_a_3_puntos_de_diferencia(): void
    {
        $pesos = Pesos::desde(AjustesIA::actuales());

        $this->assertEqualsWithDelta(3.0, sqrt($pesos->pareja / $pesos->equilibrio), 0.001);
    }

    public function test_rival_antiguo_no_gana_al_equilibrio(): void
    {
        [$p9, $p8, $p7, $p6, $p5, $p4, $p3, $p2] = $ids = $this->jugadores([9, 8, 7, 6, 5, 4, 3, 2]);
        $this->ajustes(lider: 1);

        // Los cruces de los repartos perfectos ya se dieron hace 2-3 semanas.
        $this->jornadaJugada('2026-06-06', [[$p9, $p5, $p6, $p2], [$p8, $p4, $p7, $p3]]);
        $this->jornadaJugada('2026-06-13', [[$p9, $p6, $p2, $p5], [$p8, $p7, $p3, $p4]]);
        $this->jornadaJugada('2026-06-20', [[$p9, $p6, $p8, $p7], [$p5, $p2, $p4, $p3]]);

        $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $ids);

        $peor = max(array_map(fn ($p) => $p['equilibrio']['diferencia'], $propuesta['calculo']['pistas']));
        $this->assertLessThanOrEqual(1.0, $peor);
    }

    // ── Frenar al líder ─────────────────────────────────────────────────────

    public function test_sin_puntos_no_hay_lider_al_que_frenar(): void
    {
        $ids = $this->jugadores(array_fill(0, 8, 5.0));

        $efectivo = NivelEfectivo::calcular($ids, 2026, Pesos::desde(AjustesIA::actuales()));

        $this->assertNull($efectivo['lider']);
        $this->assertSame(array_fill_keys($ids, 5.0), $efectivo['niveles']);
    }

    public function test_el_plus_del_lider_crece_con_su_ventaja(): void
    {
        [$a, $b] = $ids = $this->jugadores(array_fill(0, 8, 5.0));
        $pesos = Pesos::desde(AjustesIA::actuales()); // normal: hasta +1.5, pleno con 3 de ventaja

        $this->puntosManuales($a, 10);
        $this->puntosManuales($b, 9);
        $this->assertSame(0.5, NivelEfectivo::calcular($ids, 2026, $pesos)['plus']);

        $this->puntosManuales($a, 10); // ventaja 11: tope
        $this->assertSame(1.5, NivelEfectivo::calcular($ids, 2026, $pesos)['plus']);
    }

    public function test_un_refuerzo_nunca_es_lider(): void
    {
        $ids      = $this->jugadores(array_fill(0, 7, 5.0));
        [$extra]  = $this->jugadores([5.0], refuerzo: true);
        $this->puntosManuales($extra, 30);

        $efectivo = NivelEfectivo::calcular([...$ids, $extra], 2026, Pesos::desde(AjustesIA::actuales()));

        $this->assertNull($efectivo['lider']);
    }

    /**
     * El caso de la queja: el líder es el mejor, hay uno claramente más
     * flojo, ponerlos juntos es el reparto más equilibrado y no repite
     * ninguna pareja. Antes lo impedía la regla de "no juntar al mejor con
     * el más flojo", calculada además con el nivel inflado del líder.
     */
    public function test_el_lider_puede_ir_con_el_mas_flojo(): void
    {
        [$lider, $b, $c, $d, $e, $f, $g, $flojo] = $ids = $this->jugadores([7.5, 6, 6, 6, 6, 6, 6, 4]);
        $this->puntosManuales($lider, 12);

        // Historial reciente sin ninguna pareja que estorbe.
        $this->jornadaJugada('2026-06-13', [[$lider, $b, $c, $d], [$e, $f, $g, $flojo]]);
        $this->jornadaJugada('2026-06-20', [[$lider, $c, $e, $g], [$b, $d, $f, $flojo]]);

        for ($i = 0; $i < 5; $i++) {
            $propuesta = $this->emparejador->proponer($this->jornadaNueva(), $ids);
            $this->assertSame($flojo, $this->companeroDe($lider, $propuesta));
        }
    }

    // ── Avisos ──────────────────────────────────────────────────────────────

    public function test_los_avisos_no_cuentan_el_propio_borrador_como_repeticion(): void
    {
        $ids     = $this->jugadores(array_fill(0, 8, 5.0));
        $jornada = $this->jornadaNueva();

        $propuesta = $this->emparejador->proponer($jornada, $ids);
        $this->emparejador->aplicar($jornada, $propuesta);

        $this->assertSame([], app(AvisosJornada::class)->para($jornada->fresh(), $propuesta));
    }

    public function test_los_avisos_no_miran_temporadas_anteriores(): void
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = $this->jugadores(array_fill(0, 8, 5.0));
        $this->jornadaJugada('2025-11-29', [[$a, $b, $c, $d], [$e, $f, $g, $h]]);

        $propuesta = ['partidos' => [
            ['pista' => 1, 'equipo_a' => [$a, $b], 'equipo_b' => [$e, $f]],
            ['pista' => 2, 'equipo_a' => [$c, $d], 'equipo_b' => [$g, $h]],
        ], 'no_juegan' => []];

        $this->assertSame([], app(AvisosJornada::class)->para($this->jornadaNueva(), $propuesta));
    }

    public function test_el_calculo_se_guarda_con_la_jornada(): void
    {
        $jornada   = $this->jornadaNueva();
        $propuesta = $this->emparejador->proponer($jornada, $this->jugadores(array_fill(0, 8, 5.0)));
        $this->emparejador->aplicar($jornada, $propuesta);

        $this->assertCount(2, $jornada->fresh()->calculo['pistas']);
    }
}

{{--
    Detalle técnico del emparejador (solo admin): cómo se sumó el coste de
    cada pista. Unidad: diferencia de suma de nivel al cuadrado (ver
    App\Services\Emparejamiento\Pesos). Recibe $calculo y $nombres.
--}}
@php
    $n   = fn ($id) => $nombres[$id] ?? "#{$id}";
    $num = fn ($x, $d = 2) => number_format((float) $x, $d, ',', '.');
    $par = fn ($ids) => $n($ids[0]).' + '.$n($ids[1]);
    $vs  = fn ($ids) => $n($ids[0]).' vs '.$n($ids[1]);
    $pesos = $calculo['pesos'];
@endphp

<details class="mt-6 soft-card p-5 text-xs text-ink-700/80">
    <summary class="cursor-pointer font-bold text-ink-800 text-sm">
        Detalle técnico del cálculo
        <span class="font-mono font-normal text-ink-700/55 ml-2">coste total {{ $num($calculo['coste_total']) }}</span>
    </summary>

    <p class="mt-3 text-[11px] text-ink-700/55">
        Unidad: (diferencia de suma de nivel)². Una pista 12 vs 10 en equilibrio normal cuesta 2² = 4.
        Gana el reparto de menor coste total. Si después corriges una pista a mano, esto sigue
        mostrando la propuesta original.
    </p>

    {{-- Pesos en vigor --}}
    <h3 class="mt-4 mb-1 font-bold text-ink-800">Pesos en vigor</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 font-mono">
        <div>equilibrio × <b>{{ $num($pesos['equilibrio']) }}</b></div>
        <div>pareja × <b>{{ $num($pesos['pareja']) }}</b>
            <span class="text-ink-700/45">(≈{{ $num(sqrt($pesos['pareja'] / $pesos['equilibrio']), 1) }} pts)</span></div>
        <div>rival × <b>{{ $num($pesos['rival']) }}</b>
            <span class="text-ink-700/45">(≈{{ $num(sqrt($pesos['rival'] / $pesos['equilibrio']), 1) }} pts)</span></div>
        <div>empate ± {{ $num($pesos['tolerancia_empate']) }}</div>
        <div>recencia pareja: ×{{ $num($pesos['decay_pareja']) }}/sem, suelo {{ $num($pesos['suelo_pareja']) }}</div>
        <div>recencia rival: ×{{ $num($pesos['decay_rival']) }}/sem</div>
        <div>plus líder máx. +{{ $num($pesos['plus_lider_max'], 1) }}</div>
    </div>
    <p class="mt-1 text-[11px] text-ink-700/45">
        "≈ pts" = diferencia de suma que se acepta antes que repetir eso la semana pasada.
        Recencia: hace n jornadas pesa suelo + (1 − suelo) × factor<sup>n−1</sup>.
    </p>

    {{-- Líder y niveles --}}
    <h3 class="mt-4 mb-1 font-bold text-ink-800">Nivel usado para cuadrar</h3>
    <p class="mb-1">
        @if($calculo['lider'])
            Líder de hoy: <b>{{ $n($calculo['lider']) }}</b>, +{{ $num($calculo['plus_lider']) }} de nivel
            (proporcional a su ventaja en puntos sobre el segundo de los convocados).
        @else
            Sin plus de líder (ajuste desactivado o empate en cabeza).
        @endif
    </p>
    <div class="flex flex-wrap gap-x-3 gap-y-1 font-mono">
        @foreach(collect($calculo['niveles'])->sortDesc() as $id => $nivel)
            <span>{{ $n($id) }} <b>{{ $num($nivel) }}</b></span>
        @endforeach
    </div>

    {{-- Coste de cada pista --}}
    <h3 class="mt-4 mb-1 font-bold text-ink-800">Coste por pista</h3>
    <div class="space-y-3">
        @foreach($calculo['pistas'] as $pista)
            @php $eq = $pista['equilibrio']; @endphp
            <div class="rounded-xl bg-cream-50 ring-1 ring-cream-200 px-4 py-3">
                <p class="font-bold text-ink-800 mb-1">
                    Pista {{ $pista['pista'] }}
                    <span class="font-mono font-normal ml-2">total {{ $num($pista['total']) }}</span>
                </p>
                <table class="w-full font-mono">
                    <tr>
                        <td class="pr-2">equilibrio</td>
                        <td class="pr-2 text-ink-700/60">{{ $num($eq['suma_a'], 1) }} vs {{ $num($eq['suma_b'], 1) }} → |dif| {{ $num($eq['diferencia']) }}; {{ $num($eq['peso']) }} × {{ $num($eq['diferencia']) }}²</td>
                        <td class="text-right">{{ $num($eq['coste']) }}</td>
                    </tr>
                    @foreach(['parejas' => $par, 'rivales' => $vs] as $tipo => $etiqueta)
                        @foreach($pista[$tipo] as $t)
                            <tr>
                                <td class="pr-2">{{ $tipo === 'parejas' ? 'pareja' : 'rival' }}</td>
                                <td class="pr-2 text-ink-700/60">
                                    {{ $etiqueta($t['ids']) }}: hace {{ implode(', ', $t['distancias']) }} jornada(s)
                                    → {{ $num($t['peso']) }} × ({{ implode(' + ', array_map(fn ($f) => $num($f, 3), $t['factores'])) }})
                                </td>
                                <td class="text-right">{{ $num($t['coste']) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>

    {{-- Búsqueda y alternativas --}}
    <h3 class="mt-4 mb-1 font-bold text-ink-800">Búsqueda</h3>
    <p>
        {{ $calculo['busqueda']['evaluados'] }} repartos evaluados
        @if($calculo['busqueda']['excluidos'])
            ({{ $calculo['busqueda']['excluidos'] }} excluidos por "Probar otro")
        @endif
        · {{ $calculo['busqueda']['empatados'] }} empatados con el mejor (se eligió uno al azar).
        @if($calculo['no_juegan'])
            · Sin pista: {{ collect($calculo['no_juegan'])->map($n)->join(', ') }}.
        @endif
    </p>

    <h3 class="mt-4 mb-1 font-bold text-ink-800">Mejores alternativas</h3>
    <table class="w-full font-mono">
        @foreach($calculo['alternativas'] as $i => $alt)
            <tr class="align-top">
                <td class="pr-2 text-ink-700/45">{{ $i + 1 }}.</td>
                <td class="pr-2">
                    @foreach($alt['pistas'] as $p)
                        <div>{{ $par($p['equipo_a']) }} <span class="text-ink-700/45">vs</span> {{ $par($p['equipo_b']) }}</div>
                    @endforeach
                </td>
                <td class="text-right">{{ $num($alt['coste']) }}</td>
            </tr>
        @endforeach
    </table>
</details>

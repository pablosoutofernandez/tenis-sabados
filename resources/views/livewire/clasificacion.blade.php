<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6">

        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="h-display text-3xl text-ink-800">{{ config('tenis.temporada.nombre') }}</h1>
                <p class="text-sm text-ink-700/70 mt-1">
                    Dobles · {{ config('tenis.temporada.ciudad') }} ·
                    {{ \Carbon\Carbon::parse(config('tenis.temporada.inicio'))->translatedFormat('j M Y') }}
                    a {{ \Carbon\Carbon::parse(config('tenis.temporada.fin'))->translatedFormat('j M Y') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <select wire:model.live="anio"
                        class="px-3 py-2 rounded-xl border border-cream-300 bg-cream-100 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-brand-300">
                    @foreach($anios as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink-700/80 cursor-pointer">
                    <input type="checkbox" wire:model.live="soloActivos" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
                    Solo los que juegan
                </label>
                @unless($editando)
                    @can('gestionar-jugadores')
                        <button wire:click="abrirEdicion"
                                class="px-3 py-2 rounded-xl bg-cream-100 ring-1 ring-cream-300 text-ink-800 text-sm font-bold hover:bg-cream-50 transition">
                            Editar puntuación
                        </button>
                    @endcan
                @endunless
            </div>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif

        {{-- Puntuación de partida, al margen de los partidos jugados en la app --}}
        @if($editando)
            <div class="soft-card p-5 mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <h2 class="font-bold text-ink-800">Puntuación de partida — {{ $anio }}</h2>
                </div>
                <p class="text-xs text-ink-700/60 mb-4">
                    Súmalo a lo que llevabais antes de usar la app, o corrige la clasificación a mano.
                    Estos puntos se suman a los que gane cada jugador jugando partidos aquí dentro, y
                    también cuentan para el algoritmo a la hora de equilibrar las jornadas.
                </p>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    @foreach($jugadoresEdicion as $j)
                        <label class="flex items-center gap-2">
                            <span class="text-xs font-bold text-ink-800 truncate flex-1">{{ $j->nombre }}</span>
                            <input type="number" step="1" wire:model="ajustesForm.{{ $j->id }}"
                                   class="w-20 px-2 py-1.5 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-2 mt-4">
                    <button wire:click="guardarAjustes"
                            class="px-4 py-2 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft transition">
                        Guardar puntuación
                    </button>
                    <button wire:click="cancelarEdicion"
                            class="px-4 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-sm font-bold transition">
                        Cancelar
                    </button>
                </div>
            </div>
        @endif

        {{-- Cuadrícula de puntos: cada punto es un set ganado --}}
        <div class="soft-card p-5 mb-6">
            <div class="flex items-baseline justify-between mb-4">
                <h2 class="h-display text-xl text-ink-800">Clasificación</h2>
                <p class="text-[11px] text-ink-700/50">Cada punto = un set ganado</p>
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[720px]">
                    {{-- Regla de la escala --}}
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-24 shrink-0"></div>
                        <div class="w-10 shrink-0"></div>
                        <div class="flex-1 relative h-4">
                            @for($m = 10; $m <= $escala; $m += 10)
                                <span class="absolute -translate-x-1/2 text-[10px] font-bold text-ink-700/45"
                                      style="left: {{ ($m / $escala) * 100 }}%">{{ $m }}</span>
                            @endfor
                        </div>
                    </div>

                    @forelse($filas as $fila)
                        <div wire:key="fila-{{ $fila->id }}"
                             class="flex items-center gap-3 py-1 border-b border-cream-200 last:border-0">
                            <div class="w-24 shrink-0 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-ball-400 shrink-0"></span>
                                <span class="text-xs font-bold text-ink-800 truncate">{{ $fila->nombre }}</span>
                            </div>

                            <div class="w-10 shrink-0 text-left font-mono text-xs font-bold text-ink-800">{{ $fila->puntos }}</div>

                            <div class="flex-1 grid gap-[2px]" style="grid-template-columns: repeat({{ $escala }}, minmax(0, 1fr));">
                                @for($i = 1; $i <= $escala; $i++)
                                    <span class="aspect-square rounded-full {{ $i <= $fila->puntos ? 'bg-ball-400' : 'bg-cream-200' }}"></span>
                                @endfor
                            </div>
                        </div>
                    @empty
                        <p class="py-10 text-center text-sm text-ink-700/55">
                            No hay resultados en {{ $anio }} todavía. Genera una jornada y anota los sets.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Detalle numérico --}}
            <div class="soft-card p-5">
                <h2 class="font-bold text-ink-800 mb-3">Partidos jugados</h2>
                <table class="w-full text-sm">
                    <thead class="text-[10px] uppercase tracking-widest text-ink-700/50 font-bold">
                        <tr>
                            <th class="py-2 text-left">Jugador</th>
                            <th class="py-2 text-center">Puntos</th>
                            <th class="py-2 text-center">Partidos</th>
                            <th class="py-2 text-center">Media</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cream-200">
                        @foreach($filas as $fila)
                            <tr>
                                <td class="py-2 font-semibold text-ink-800">{{ $fila->nombre }}</td>
                                <td class="py-2 text-center font-mono text-xs font-bold text-ink-800">{{ $fila->puntos }}</td>
                                <td class="py-2 text-center text-ink-700/70">{{ $fila->jugados }}</td>
                                <td class="py-2 text-center font-mono text-xs text-ink-700/70">
                                    {{ $fila->jugados ? number_format($fila->puntos_partidos / $fila->jugados, 2) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Normas --}}
            <div class="soft-card p-5">
                <h2 class="font-bold text-ink-800 mb-3">Normas {{ $anio }}</h2>
                <ul class="space-y-2">
                    @foreach($normas as $norma)
                        <li class="flex gap-2 text-sm text-ink-700/80">
                            <span class="text-brand-300 shrink-0">·</span>
                            <span>{{ $norma }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="text-[11px] text-ink-700/45 mt-4">
                    Estas normas se editan en <code class="font-mono">config/tenis.php</code> y son las mismas
                    que usa el algoritmo al montar los emparejamientos.
                </p>
            </div>
        </div>
    </main>
</div>

<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-5xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Historial</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Cada sábado jugado, con sus parejas y sets. Es lo que usa el algoritmo para no repetir
                emparejamientos, y lo que mueve el nivel de cada jugador tras cada resultado.
            </p>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif

        <div class="space-y-6">
            @forelse($jornadas as $jornada)
                <section wire:key="jornada-{{ $jornada->id }}" class="soft-card p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4 pb-3 border-b border-cream-200">
                        <h2 class="h-display text-xl text-ink-800">{{ $jornada->fecha->translatedFormat('l j \d\e F Y') }}</h2>
                        <div class="flex items-center gap-2 text-[11px] font-bold">
                            <span class="px-2 py-0.5 rounded-md {{ $jornada->estaCompleta() ? 'bg-emerald-500/10 text-emerald-300' : 'bg-cream-100 text-ink-700/60' }}">
                                {{ $jornada->estaCompleta() ? 'Resultados completos' : 'Faltan resultados' }}
                            </span>
                            <a href="{{ route('jornada.ver', $jornada->id) }}" wire:navigate
                               class="text-brand-300 hover:text-white">Ver emparejamientos</a>
                        </div>
                    </div>

                    <div class="space-y-3">
                        @foreach($jornada->partidos as $partido)
                            @php
                                $a = $partido->equipo('a');
                                $b = $partido->equipo('b');
                            @endphp
                            <div wire:key="hist-partido-{{ $partido->id }}"
                                 class="rounded-xl ring-1 ring-cream-200 bg-cream-100 px-4 py-3">

                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-[10px] font-bold text-ink-700/45 w-12 shrink-0">Pista {{ $partido->pista }}</span>

                                    <div class="flex-1 min-w-[220px] flex items-center gap-3">
                                        <span class="text-sm font-semibold text-ink-800">{{ $a->pluck('nombre')->join(' + ') }}</span>
                                        <span class="font-mono text-sm font-bold {{ $partido->jugado() ? 'text-ink-800' : 'text-ink-700/30' }}">
                                            {{ $partido->jugado() ? $partido->sets_a.' – '.$partido->sets_b : '– –' }}
                                        </span>
                                        <span class="text-sm font-semibold text-ink-800">{{ $b->pluck('nombre')->join(' + ') }}</span>
                                    </div>

                                    <div class="ml-auto flex items-center gap-2">
                                        @if($partido->retirado_id)
                                            <span class="text-[10px] font-bold text-amber-300 bg-amber-500/10 px-2 py-0.5 rounded-md">
                                                Retirada
                                            </span>
                                        @endif
                                        @can('registrar-resultado', $partido)
                                            <button wire:click="abrir({{ $partido->id }})"
                                                    class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                                {{ $partido->jugado() ? 'Editar' : 'Anotar sets' }}
                                            </button>
                                            @if($partido->jugado())
                                                <button wire:click="borrarResultado({{ $partido->id }})"
                                                        wire:confirm="¿Borrar el resultado de este partido?"
                                                        class="px-3 py-1.5 rounded-lg text-ink-700/50 hover:text-rose-300 text-xs font-bold transition">
                                                    Borrar
                                                </button>
                                            @endif
                                        @endcan
                                    </div>
                                </div>

                                {{-- Formulario de resultado --}}
                                @if($editando === $partido->id)
                                    <div class="mt-3 pt-3 border-t border-cream-200 flex flex-wrap items-end gap-4">
                                        <div>
                                            <label class="block text-[10px] font-bold text-ink-700/60 mb-1">Sets {{ $a->pluck('nombre')->join(' + ') }}</label>
                                            <input type="number" min="0" max="5" wire:model="resultados.{{ $partido->id }}.sets_a"
                                                   class="w-20 px-3 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold text-ink-700/60 mb-1">Sets {{ $b->pluck('nombre')->join(' + ') }}</label>
                                            <input type="number" min="0" max="5" wire:model="resultados.{{ $partido->id }}.sets_b"
                                                   class="w-20 px-3 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold text-ink-700/60 mb-1">¿Alguien se retiró?</label>
                                            <select wire:model="resultados.{{ $partido->id }}.retirado_id"
                                                    class="px-3 py-2 rounded-lg border border-cream-300 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                                                <option value="">Nadie</option>
                                                @foreach($partido->jugadores as $j)
                                                    <option value="{{ $j->id }}">{{ $j->nombre }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="flex gap-2">
                                            <button wire:click="guardarResultado({{ $partido->id }})"
                                                    class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold transition">
                                                Guardar
                                            </button>
                                            <button wire:click="cerrar"
                                                    class="px-4 py-2 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                                Cancelar
                                            </button>
                                        </div>
                                        <p class="w-full text-[11px] text-ink-700/50">
                                            1 punto por set ganado, con tope de {{ config('tenis.puntos_max_por_partido') }} por jugador.
                                            Si alguien se retira, los rivales se llevan los {{ config('tenis.puntos_max_por_partido') }} puntos
                                            y su compañero suma 1 más.
                                        </p>
                                    </div>
                                @elseif($partido->jugado() && $verPuntuaciones)
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                        @foreach($partido->jugadores as $j)
                                            <span class="text-[11px] text-ink-700/55">
                                                {{ $j->nombre }} <span class="font-mono font-bold text-ink-800">+{{ $j->pivot->puntos }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if($jornada->sinPista->count())
                            <p class="text-[11px] text-ink-700/50 pt-1">
                                Se quedaron sin jugar: {{ $jornada->sinPista->pluck('nombre')->join(', ') }}
                            </p>
                        @endif
                    </div>
                </section>
            @empty
                <div class="soft-card p-12 text-center">
                    <p class="text-sm font-bold text-ink-800">Aún no hay jornadas</p>
                    <p class="text-xs text-ink-700/55 mt-1">
                        Empieza por <a href="{{ route('jornada.generar') }}" wire:navigate class="text-brand-300 font-bold">montar la primera</a>.
                    </p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">{{ $jornadas->links() }}</div>
    </main>
</div>

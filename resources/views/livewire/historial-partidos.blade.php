<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-5xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Partidos</h1>
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
                            {{-- Esa pantalla exige 'gestionar-jornadas' al entrar: si el usuario
                                 no lo tiene, el enlace solo le llevaría a un 403. --}}
                            @can('gestionar-jornadas')
                                <a href="{{ route('jornada.ver', $jornada->id) }}" wire:navigate
                                   class="text-brand-300 hover:text-white">Ver emparejamientos</a>
                            @endcan
                        </div>
                    </div>

                    <div class="space-y-3">
                        @foreach($jornada->partidos as $partido)
                            @php
                                $a = $partido->equipo('a');
                                $b = $partido->equipo('b');
                            @endphp
                            <div wire:key="hist-partido-{{ $partido->id }}"
                                 class="rounded-xl ring-1 ring-cream-200 bg-cream-100 px-4 py-4">

                                <div class="flex items-center justify-between gap-2 mb-3">
                                    <span class="text-[10px] font-bold text-ink-700/45">Pista {{ $partido->pista }}</span>

                                    <div class="flex items-center gap-2">
                                        @if($partido->retirado_id)
                                            <span class="text-[10px] font-bold text-amber-300 bg-amber-500/10 px-2 py-0.5 rounded-md">
                                                Retirada
                                            </span>
                                        @endif

                                        {{-- Si NO está jugado: permite anotar con 'registrar-resultado' --}}
                                        @if(! $partido->jugado())
                                            @can('registrar-resultado', $partido)
                                                <button wire:click="abrir({{ $partido->id }})"
                                                        class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition ring-1 ring-cream-300">
                                                    Anotar sets
                                                </button>
                                            @endcan
                                        @else
                                            {{-- Si YA está jugado: requiere el permiso 'editar-resultado' --}}
                                            @can('editar-resultado')
                                                <button wire:click="abrir({{ $partido->id }})"
                                                        class="px-2.5 py-1 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-[11px] font-bold transition">
                                                    Editar
                                                </button>
                                                <button wire:click="borrarResultado({{ $partido->id }})"
                                                        wire:confirm="¿Borrar el resultado de este partido?"
                                                        class="px-2.5 py-1 rounded-lg text-ink-700/50 hover:text-rose-300 text-[11px] font-bold transition">
                                                    Borrar
                                                </button>
                                            @endcan
                                        @endif
                                    </div>
                                </div>

                                <div class="text-center">
                                    <p class="text-sm font-semibold text-ink-800">{{ $a->pluck('nombre')->join(' + ') }}</p>

                                    <p class="font-mono text-2xl font-bold my-0.5 {{ $partido->jugado() ? 'text-ink-800' : 'text-ink-700/30' }}">
                                        {{ $partido->jugado() ? $partido->sets_a.' – '.$partido->sets_b : '—' }}
                                    </p>

                                    @if($partido->jugado() && $partido->detalle_sets)
                                        <p class="text-[11px] font-mono text-ink-700/40 mb-1">
                                            {{ collect($partido->detalle_sets)->map(fn ($s) => $s['a'].'-'.$s['b'])->join('  ') }}
                                        </p>
                                    @endif

                                    <p class="text-sm font-semibold text-ink-800">{{ $b->pluck('nombre')->join(' + ') }}</p>
                                </div>

                                {{-- Formulario de resultado --}}
                                @if($editando === $partido->id)
                                    <div class="mt-3 pt-3 border-t border-cream-200">

                                        <p class="text-[10px] font-bold uppercase tracking-widest text-ink-700/45 mb-2">Sets regulares</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                            @foreach([0 => 'Set 1', 1 => 'Set 2', 2 => 'Set 3'] as $i => $etiqueta)
                                                <div>
                                                    <label class="block text-[10px] font-bold text-ink-700/60 mb-1">{{ $etiqueta }}</label>
                                                    <div class="flex items-center gap-1.5">
                                                        <input type="number" min="0" max="30"
                                                               wire:model="resultados.{{ $partido->id }}.sets.{{ $i }}.a"
                                                               placeholder="{{ $a->pluck('nombre')->join('+') }}"
                                                               class="w-full min-w-0 px-2 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                                        <span class="text-ink-700/35 shrink-0">–</span>
                                                        <input type="number" min="0" max="30"
                                                               wire:model="resultados.{{ $partido->id }}.sets.{{ $i }}.b"
                                                               placeholder="{{ $b->pluck('nombre')->join('+') }}"
                                                               class="w-full min-w-0 px-2 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                                    </div>
                                                    @error('resultados.'.$partido->id.'.sets.'.$i.'.a')<p class="text-[10px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                                                    @error('resultados.'.$partido->id.'.sets.'.$i.'.b')<p class="text-[10px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                                                </div>
                                            @endforeach
                                        </div>

                                        {{-- Súper Tie-Break opcional (índice 3) --}}
                                        <div class="rounded-xl bg-ball-400/10 ring-1 ring-ball-400/20 p-3 mb-3">
                                            <p class="text-[10px] font-bold uppercase tracking-widest text-ink-700/45 mb-2">
                                                Súper tie-break — opcional
                                            </p>
                                            <div class="max-w-[240px]">
                                                <div class="flex items-center gap-1.5">
                                                    <input type="number" min="0" max="30"
                                                           wire:model="resultados.{{ $partido->id }}.sets.3.a"
                                                           placeholder="{{ $a->pluck('nombre')->join('+') }}"
                                                           class="w-full min-w-0 px-2 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                                    <span class="text-ink-700/35 shrink-0">–</span>
                                                    <input type="number" min="0" max="30"
                                                           wire:model="resultados.{{ $partido->id }}.sets.3.b"
                                                           placeholder="{{ $b->pluck('nombre')->join('+') }}"
                                                           class="w-full min-w-0 px-2 py-2 rounded-lg border border-cream-300 text-sm text-center focus:outline-none focus:ring-2 focus:ring-brand-300">
                                                </div>
                                                @error('resultados.'.$partido->id.'.sets.3.a')<p class="text-[10px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                                                @error('resultados.'.$partido->id.'.sets.3.b')<p class="text-[10px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                                            </div>
                                            <p class="text-[10px] text-ink-700/45 mt-2">
                                                Déjalo en 0-0 si no se disputó.
                                            </p>
                                        </div>

                                        <div class="flex flex-wrap items-end gap-3">
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
                                        </div>
                                        <p class="text-[11px] text-ink-700/50 mt-3">
                                            1 punto por set ganado, con tope de {{ config('tenis.puntos_max_por_partido') }} por jugador.
                                            Si alguien se retira, los rivales se llevan los {{ config('tenis.puntos_max_por_partido') }} puntos
                                            y su compañero suma 1 más.
                                        </p>
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

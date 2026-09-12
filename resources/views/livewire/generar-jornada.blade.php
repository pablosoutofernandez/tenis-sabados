<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-6xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Montar la jornada</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Marca quién está disponible este sábado. El algoritmo reparte 2 o 3 pistas equilibrando
                niveles y puntos, y evitando repetir parejas y enfrentamientos recientes.
            </p>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif
        @if($error)
            <div class="mb-4 px-4 py-3 rounded-xl bg-rose-500/10 text-rose-300 text-sm font-semibold ring-1 ring-rose-500/30">
                {{ $error }}
            </div>
        @endif

        {{-- Disponibilidad --}}
        <div class="soft-card p-5 mb-6">
            <div class="flex flex-wrap items-end gap-4 mb-4">
                <div>
                    <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Sábado</label>
                    <input type="date" wire:model.live="fecha"
                           class="px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                </div>
                <div class="flex items-center gap-3 pb-1">
                    <span class="px-3 py-1.5 rounded-lg bg-cream-100 text-xs font-bold text-ink-800">
                        {{ count($disponibles) }} disponibles
                    </span>
                    <span class="px-3 py-1.5 rounded-lg bg-brand-50 text-xs font-bold text-brand-300">
                        {{ $this->pistas }} {{ $this->pistas === 1 ? 'partido' : 'partidos' }}
                    </span>
                    @if($this->sobran > 0)
                        <span class="px-3 py-1.5 rounded-lg bg-amber-500/10 text-xs font-bold text-amber-300">
                            {{ $this->sobran }} se queda{{ $this->sobran === 1 ? '' : 'n' }} sin jugar
                        </span>
                    @endif
                </div>
                <div class="flex gap-2 ml-auto pb-1">
                    <button wire:click="marcarTodos" class="text-xs font-bold text-brand-300 hover:text-white">Marcar todos</button>
                    <span class="text-ink-700/25">|</span>
                    <button wire:click="vaciar" class="text-xs font-bold text-ink-700/50 hover:text-ink-800">Ninguno</button>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                @foreach($jugadores as $j)
                    @php $dentro = in_array($j->id, $disponibles, true); @endphp
                    <button wire:click="alternar({{ $j->id }})" wire:key="disp-{{ $j->id }}"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-left transition ring-1
                                   {{ $dentro ? 'bg-brand-50 ring-brand-300' : 'bg-cream-100 ring-cream-200 hover:bg-cream-50' }}">
                        <span class="w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0
                                     {{ $dentro ? 'bg-brand-500 text-white' : 'bg-cream-200 text-ink-700/60' }}">
                            {{ $j->iniciales }}
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-ink-800 truncate">{{ $j->nombre }}</span>
                            <span class="block text-[10px] font-mono text-ink-700/50">nivel {{ number_format($j->nivel, 1) }}</span>
                        </span>
                    </button>
                @endforeach
            </div>

            <div class="flex items-center gap-3 mt-4">
                <button wire:click="generar" wire:loading.attr="disabled" wire:target="generar"
                        class="px-5 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition disabled:opacity-50">
                    <span wire:loading.remove wire:target="generar">{{ $jornada?->partidos->count() ? 'Volver a repartir' : 'Generar emparejamientos' }}</span>
                    <span wire:loading wire:target="generar">Repartiendo pistas…</span>
                </button>
                <p wire:loading wire:target="generar" class="text-xs text-ink-700/55">Puede tardar unos segundos.</p>
            </div>
        </div>

        {{-- Propuesta --}}
        @if($jornada && $jornada->partidos->count())
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <h2 class="h-display text-xl text-ink-800">
                    {{ $jornada->fecha->translatedFormat('l j \d\e F') }}
                    <span class="text-xs font-sans font-bold px-2 py-0.5 rounded-md ml-1
                                 {{ $jornada->estado === 'publicada' ? 'bg-emerald-500/10 text-emerald-300' : 'bg-cream-100 text-ink-700/60' }}">
                        {{ ucfirst($jornada->estado) }}
                    </span>
                </h2>
                <div class="flex gap-2">
                    @if($jornada->estado !== 'publicada')
                        <button wire:click="publicar"
                                class="px-4 py-2 rounded-xl bg-cream-200 hover:bg-cream-300 text-ink-800 text-xs font-bold transition">
                            Publicar jornada
                        </button>
                    @endif
                    @can('eliminar-jornada')
                        <button wire:click="eliminarJornada"
                            wire:confirm="¿Eliminar esta jornada con sus partidos y resultados?"
                            class="px-4 py-2 rounded-xl bg-rose-500/10 text-rose-300 ring-1 ring-rose-500/30 text-xs font-bold hover:bg-rose-500/20 transition">
                        Eliminar jornada
                    </button>
                    @endcan
                </div>
            </div>

            {{-- Repeticiones que no se han podido evitar --}}
            @if($avisos)
                <div class="mb-4 px-4 py-3 rounded-xl bg-amber-500/10 ring-1 ring-amber-500/30 text-sm text-amber-200">
                    <p class="font-bold mb-1">Revisa esto antes de publicar:</p>
                    <ul class="space-y-0.5">
                        @foreach($avisos as $aviso)
                            <li class="text-[13px]">· {{ $aviso }}</li>
                        @endforeach
                    </ul>
                    <p class="text-[11px] mt-2">
                        Puedes volver a repartir a ver si sale mejor, corregir la pista a mano (botón "Corregir"),
                        o dejarlo así. Si esto se repite jornada tras jornada, sube la prioridad de
                        <a href="{{ route('ajustes-ia') }}" wire:navigate class="underline font-bold">"Variedad" en Ajustes</a>.
                    </p>
                </div>
            @endif

            @if($jornada->explicacion_ia)
                <div class="mb-4 px-4 py-3 rounded-xl bg-cream-50 ring-1 ring-cream-200 text-sm text-ink-700/80">
                    {{ $jornada->explicacion_ia }}
                </div>
            @endif

            <div class="grid md:grid-cols-2 gap-4">
                @foreach($jornada->partidos as $partido)
                    <div wire:key="partido-{{ $partido->id }}" class="soft-card p-5">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-xs font-bold text-ink-700/50">Pista {{ $partido->pista }}</span>
                            @if($editandoPartidoId !== $partido->id)
                                @unless($partido->jugado())
                                    <button wire:click="editarPartido({{ $partido->id }})"
                                            class="text-[11px] font-bold text-brand-300 hover:text-white transition">
                                        Corregir
                                    </button>
                                @endunless
                            @endif
                        </div>

                        @if($editandoPartidoId === $partido->id)
                            {{-- Edición: toca a un jugador para cambiarlo de pareja --}}
                            <div class="grid grid-cols-2 gap-3 mb-3">
                                @foreach(['a' => 'Pareja A', 'b' => 'Pareja B'] as $letra => $titulo)
                                    <div class="rounded-xl bg-cream-50 ring-1 ring-cream-200 px-3 py-3 min-h-[84px]">
                                        <p class="text-[10px] font-bold text-ink-700/45 mb-2">{{ $titulo }}</p>
                                        <div class="space-y-1.5">
                                            @foreach($partido->jugadores as $j)
                                                @continue(($equiposEdicion[$j->id] ?? null) !== $letra)
                                                <button wire:click="alternarEquipo({{ $j->id }})"
                                                        class="w-full text-left px-2 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-xs font-bold text-ink-800 transition">
                                                    {{ $j->nombre }}
                                                    <span class="font-mono font-normal text-ink-700/45">{{ number_format($j->nivel, 1) }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <p class="text-[10px] text-ink-700/45 mb-3">Toca a un jugador para pasarlo a la otra pareja.</p>

                            <div class="flex gap-2">
                                <button wire:click="guardarEdicionPartido"
                                        class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-bold transition">
                                    Guardar corrección
                                </button>
                                <button wire:click="cancelarEdicionPartido"
                                        class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                    Cancelar
                                </button>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach(['a' => 'Pareja A', 'b' => 'Pareja B'] as $letra => $titulo)
                                    <div class="rounded-xl bg-cream-50 ring-1 ring-cream-200 px-4 py-3">
                                        <p class="text-[10px] font-bold text-ink-700/45 mb-1.5">{{ $titulo }}</p>
                                        <div class="flex flex-wrap gap-x-3 gap-y-1">
                                            @foreach($partido->equipo($letra) as $j)
                                                <span class="text-sm font-bold text-ink-800">
                                                    {{ $j->nombre }}
                                                    <span class="font-mono text-[10px] font-normal text-ink-700/45">{{ number_format($j->nivel, 1) }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                    @if($letra === 'a')
                                        <p class="text-center text-[11px] font-bold text-ink-700/35">contra</p>
                                    @endif
                                @endforeach
                            </div>

                            @if($partido->motivo_ia)
                                <p class="text-[11px] text-ink-700/60 mt-3 leading-snug">{{ $partido->motivo_ia }}</p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>

            @if($jornada->sinPista->count())
                <div class="mt-4 px-4 py-3 rounded-xl bg-cream-100 ring-1 ring-cream-200 text-sm">
                    <span class="font-bold text-ink-800">Hoy se quedan sin jugar: </span>
                    <span class="text-ink-700/75">{{ $jornada->sinPista->pluck('nombre')->join(', ') }}</span>
                </div>
            @endif

            <p class="text-[11px] text-ink-700/45 mt-4">
                Los resultados se anotan en <a href="{{ route('historial') }}" wire:navigate class="font-bold text-brand-300">Historial</a>.
            </p>
        @endif
    </main>
</div>

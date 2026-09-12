<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-2xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Mis estadísticas</h1>
            <p class="text-sm text-ink-700/70 mt-1">Lo tuyo, esta temporada.</p>
        </header>

        @if(! $jugador)
            <div class="soft-card p-6 text-center">
                <div class="text-4xl mb-3">🔗</div>
                <p class="text-sm font-bold text-ink-800">Tu cuenta no está vinculada a ningún jugador</p>
                <p class="text-xs text-ink-700/55 mt-1.5 leading-relaxed">
                    Pide al administrador que vincule tu cuenta con tu ficha del torneo, en la sección de Usuarios.
                    En cuanto lo haga, aquí verás tu nivel, tus puntos y tus partidos.
                </p>
            </div>
        @else
            {{-- Cabecera con nivel --}}
            <div class="soft-card p-5 mb-4 flex items-center gap-4">
                <div class="w-14 h-14 rounded-full bg-gradient-to-br from-brand-100 to-cream-200 flex items-center justify-center text-lg font-bold text-brand-300 shrink-0">
                    {{ $jugador->iniciales }}
                </div>
                <div class="min-w-0">
                    <p class="font-bold text-ink-800 text-lg truncate">{{ $jugador->nombre }}</p>
                    <p class="text-xs text-ink-700/55">
                        Nivel <span class="font-mono font-bold text-ink-800">{{ number_format($jugador->nivel, 2) }}</span>
                    </p>
                </div>
            </div>

            {{-- Cifras del año --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                <div class="soft-card p-4 text-center">
                    <p class="text-2xl font-bold text-ink-800 font-mono">{{ $puntos }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">puntos</p>
                </div>
                <div class="soft-card p-4 text-center">
                    <p class="text-2xl font-bold text-ink-800 font-mono">{{ $partidosJugados }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">partidos</p>
                </div>
                <div class="soft-card p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-300 font-mono">{{ $victorias }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">victorias</p>
                </div>
                <div class="soft-card p-4 text-center">
                    <p class="text-2xl font-bold text-rose-300 font-mono">{{ $derrotas }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">derrotas</p>
                </div>
            </div>

            @if($companeroFavorito)
                <div class="mb-4 px-4 py-3 rounded-xl bg-cream-100 ring-1 ring-cream-200 text-sm text-ink-700/80">
                    Tu compañero más frecuente esta temporada: <span class="font-bold text-ink-800">{{ $companeroFavorito }}</span>
                </div>
            @endif

            {{-- Partidos recientes --}}
            <div class="soft-card p-5">
                <h2 class="font-bold text-ink-800 mb-3">Últimos partidos</h2>

                @forelse($recientes as $r)
                    <div class="flex items-center gap-3 py-2.5 border-b border-cream-200 last:border-0">
                        <span class="w-2 h-2 rounded-full shrink-0 {{ $r['gane'] ? 'bg-emerald-400' : 'bg-rose-400' }}"></span>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-ink-800">
                                @if($r['companero'])
                                    Con <span class="font-semibold">{{ $r['companero'] }}</span>
                                @else
                                    Sin compañero registrado
                                @endif
                                @if($r['retirado'])
                                    <span class="text-[10px] text-amber-300 font-bold ml-1">retirada</span>
                                @endif
                            </p>
                            <p class="text-[11px] text-ink-700/45">{{ $r['fecha']->translatedFormat('j \d\e F') }}</p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="font-mono text-sm font-bold {{ $r['gane'] ? 'text-emerald-300' : 'text-rose-300' }}">
                                {{ $r['marcador'] }}
                            </p>
                            @if($r['delta'] != 0)
                                <p class="text-[10px] font-mono {{ $r['delta'] > 0 ? 'text-emerald-300/70' : 'text-rose-300/70' }}">
                                    {{ $r['delta'] > 0 ? '+' : '' }}{{ number_format($r['delta'], 2) }}
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/50 text-center py-6">
                        Todavía no tienes partidos jugados esta temporada.
                    </p>
                @endforelse
            </div>
        @endif
    </main>
</div>

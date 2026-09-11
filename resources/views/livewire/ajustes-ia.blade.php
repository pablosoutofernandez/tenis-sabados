<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-2xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Ajustes</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Cómo reparte el algoritmo las pistas cada sábado.
            </p>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif

        {{-- Preajustes rápidos --}}
        <div class="mb-4 flex flex-wrap gap-2">
            <button wire:click="preset('equilibrado')"
                    class="px-3 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                ⚖️ Equilibrado
            </button>
            <button wire:click="preset('variedad')"
                    class="px-3 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                🔀 Máxima variedad
            </button>
            <button wire:click="preset('equilibrio')"
                    class="px-3 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                🎯 Máximo equilibrio
            </button>
            <button wire:click="preset('competitivo')"
                    class="px-3 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                🔥 Competitivo
            </button>
        </div>

        {{-- Sliders --}}
        <div class="soft-card p-5 mb-4">
            <div class="space-y-6">
                <div>
                    <div class="flex items-baseline justify-between mb-1.5">
                        <label class="text-sm font-bold text-ink-800">Variedad</label>
                        <span class="text-xs font-mono text-brand-300">{{ \App\Models\AjustesIA::etiqueta($prioridadNoRepetir) }}</span>
                    </div>
                    <input type="range" min="1" max="5" step="1" wire:model.live="prioridadNoRepetir" class="w-full accent-brand-500">
                    <p class="text-[11px] text-ink-700/50 mt-1">Cuánto evita repetir parejas y cruces recientes.</p>
                </div>

                <div>
                    <div class="flex items-baseline justify-between mb-1.5">
                        <label class="text-sm font-bold text-ink-800">Equilibrio</label>
                        <span class="text-xs font-mono text-brand-300">{{ \App\Models\AjustesIA::etiqueta($prioridadEquilibrio) }}</span>
                    </div>
                    <input type="range" min="1" max="5" step="1" wire:model.live="prioridadEquilibrio" class="w-full accent-brand-500">
                    <p class="text-[11px] text-ink-700/50 mt-1">Cuánto le importa que las dos parejas de cada pista tengan nivel parecido.</p>
                </div>

                <div>
                    <div class="flex items-baseline justify-between mb-1.5">
                        <label class="text-sm font-bold text-ink-800">Frenar al líder</label>
                        <span class="text-xs font-mono text-brand-300">{{ \App\Models\AjustesIA::etiqueta($prioridadFrenarLider) }}</span>
                    </div>
                    <input type="range" min="1" max="5" step="1" wire:model.live="prioridadFrenarLider" class="w-full accent-brand-500">
                    <p class="text-[11px] text-ink-700/50 mt-1">Cuánto le complica el partido a quien va primero en puntos.</p>
                </div>
            </div>

            {{-- Resumen en vivo --}}
            <div class="mt-5 px-4 py-3 rounded-xl bg-cream-50 ring-1 ring-cream-200">
                <p class="text-xs text-ink-700/70">
                    <span class="font-bold text-ink-800">Con esto:</span>
                    {{ \App\Models\AjustesIA::fraseVariedad($prioridadNoRepetir) }},
                    {{ \App\Models\AjustesIA::fraseEquilibrio($prioridadEquilibrio) }},
                    y {{ \App\Models\AjustesIA::fraseLider($prioridadFrenarLider) }}.
                </p>
            </div>

            <button wire:click="guardar"
                    class="mt-4 px-5 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition">
                Guardar ajustes
            </button>
        </div>

        {{-- Estadísticas de la temporada --}}
        <div class="soft-card p-5">
            <h2 class="text-xs font-bold uppercase tracking-widest text-ink-700/45 mb-3">Esta temporada</h2>
            <div class="grid grid-cols-3 gap-3">
                <div class="text-center">
                    <p class="text-2xl font-bold text-ink-800 font-mono">{{ $jornadasJugadas }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">jornadas jugadas</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-ink-800 font-mono">{{ $parejasDistintas }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">parejas distintas</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-ink-800 font-mono">{{ $parejaMasVista }}</p>
                    <p class="text-[11px] text-ink-700/50 mt-0.5">veces la pareja más repetida</p>
                </div>
            </div>
        </div>
    </main>
</div>

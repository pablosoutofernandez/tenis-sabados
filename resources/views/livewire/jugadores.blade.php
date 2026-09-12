<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-6xl">

        <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="h-display text-3xl text-ink-800">Jugadores</h1>
                <p class="text-sm text-ink-700/70 mt-1">
                    El nivel de partida lo pones tú; después se ajusta solo tras cada resultado
                    (sistema de Elo). Puedes corregirlo a mano cuando quieras.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button wire:click="nuevo"
                        class="px-4 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition">
                    Añadir jugador
                </button>
            </div>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif

        {{-- Formulario --}}
        @if($formVisible)
            <div class="mb-6 soft-card p-5">
                <h2 class="font-bold text-ink-800 mb-4">{{ $jugadorId ? 'Editar jugador' : 'Nuevo jugador' }}</h2>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Nombre</label>
                        <input type="text" wire:model.blur="nombre"
                               class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                        @error('nombre')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Nivel</label>
                        <input type="number" min="1" step="0.1" wire:model.blur="nivel"
                               class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                        <p class="text-[10px] text-ink-700/45 mt-1">
                            Sin tope: si alguien juega muy por encima, el Elo lo empuja más allá de 10.
                        </p>
                        @error('nivel')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-end gap-4 pb-2">
                        <label class="flex items-center gap-2 text-sm font-semibold text-ink-800 cursor-pointer">
                            <input type="checkbox" wire:model="activo" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
                            Juega esta temporada
                        </label>
                        <label class="flex items-center gap-2 text-sm font-semibold text-ink-800 cursor-pointer">
                            <input type="checkbox" wire:model="es_refuerzo" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
                            Jugador de refuerzo
                        </label>
                    </div>
                </div>
                <p class="text-[10px] text-ink-700/45 mt-2">
                    Un refuerzo se apunta igual que cualquiera y su nivel se ajusta igual tras cada resultado,
                    pero no sale en la clasificación y no se marca disponible por defecto al montar jornada.
                </p>

                <div class="flex gap-2 mt-4">
                    <button wire:click="guardar"
                            class="px-4 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft transition">
                        Guardar jugador
                    </button>
                    <button wire:click="cancelar"
                            class="px-4 py-2.5 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-sm font-bold transition">
                        Cancelar
                    </button>
                </div>
            </div>
        @endif

        {{-- Filtros --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar jugador…"
                   class="w-full md:w-80 px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
            <label class="flex items-center gap-2 text-sm font-semibold text-ink-700/80 cursor-pointer">
                <input type="checkbox" wire:model.live="soloActivos" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
                Solo los que juegan
            </label>
        </div>

        {{-- Tabla --}}
        <div class="bg-cream-100 rounded-2xl ring-1 ring-cream-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-cream-50 text-[10px] uppercase tracking-widest text-ink-700/50 font-bold">
                        <tr>
                            <th class="px-4 py-3 text-left">Jugador</th>
                            <th class="px-4 py-3 text-left">Nivel</th>
                            <th class="px-4 py-3 text-center">Puntos</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cream-200">
                        @forelse($jugadores as $j)
                            <tr wire:key="jugador-{{ $j->id }}" class="hover:bg-cream-50/60">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-brand-100 to-cream-200 flex items-center justify-center text-xs font-bold text-brand-300">
                                            {{ $j->iniciales }}
                                        </div>
                                        <div>
                                            <p class="font-semibold text-ink-800 flex items-center gap-1.5">
                                                {{ $j->nombre }}
                                                @if($j->es_refuerzo)
                                                    <span class="px-1.5 py-0.5 rounded bg-brand-500/10 text-brand-300 text-[9px] font-bold uppercase tracking-wider">Refuerzo</span>
                                                @endif
                                            </p>
                                            @unless($j->activo)
                                                <p class="text-[10px] text-ink-700/45 font-semibold">No juega esta temporada</p>
                                            @endunless
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-20 h-1.5 rounded-full bg-cream-200 overflow-hidden">
                                            <div class="h-full bg-brand-500" style="width: {{ min($j->nivel * 10, 100) }}%"></div>
                                        </div>
                                        <span class="font-mono text-xs text-ink-700/70">{{ number_format($j->nivel, 1) }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-bold text-ink-800">{{ $puntos[$j->id] ?? 0 }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($confirmandoId === $j->id)
                                        <div class="inline-flex items-center gap-2">
                                            <span class="text-[11px] font-semibold text-rose-300">¿Seguro?</span>
                                            <button wire:click="eliminar({{ $j->id }})"
                                                    class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition">
                                                Sí, eliminar
                                            </button>
                                            <button wire:click="cancelarConfirmacion"
                                                    class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                                Cancelar
                                            </button>
                                        </div>
                                    @else
                                        <div class="inline-flex items-center gap-2">
                                            <button wire:click="editar({{ $j->id }})"
                                                    class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                                Editar
                                            </button>
                                            <button wire:click="pedirConfirmacion({{ $j->id }})"
                                                    class="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 ring-1 ring-rose-500/30 text-xs font-bold transition">
                                                Eliminar
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-12 text-center">
                                    <p class="text-sm font-bold text-ink-800">Todavía no hay jugadores</p>
                                    <p class="text-xs text-ink-700/55 mt-1">Añade al menos cuatro para poder montar una pista.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-cream-100 bg-cream-50/50">
                {{ $jugadores->links() }}
            </div>
        </div>
    </main>
</div>

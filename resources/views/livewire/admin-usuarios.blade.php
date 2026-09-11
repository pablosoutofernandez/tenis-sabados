<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-5xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Usuarios</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Activa las cuentas nuevas, dales rol y vincúlalas con su ficha de jugador.
            </p>
        </header>

        @if(session('success'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-emerald-500/10 text-emerald-300 text-sm font-semibold ring-1 ring-emerald-500/30">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 px-4 py-3 rounded-xl bg-rose-500/10 text-rose-300 text-sm font-semibold ring-1 ring-rose-500/30">
                {{ session('error') }}
            </div>
        @endif

        @if($passwordTemporal)
            <div class="mb-4 px-4 py-3 rounded-xl bg-amber-500/10 ring-1 ring-amber-500/30">
                <p class="text-sm font-bold text-amber-200 mb-1">Contraseña temporal (se muestra una sola vez)</p>
                <p class="font-mono text-sm text-amber-100 select-all">{{ $passwordTemporal }}</p>
                <p class="text-[11px] text-amber-200/70 mt-1.5">
                    Pásasela en persona. Al entrar tendrá que cambiarla obligatoriamente.
                </p>
                <button wire:click="ocultarPassword" class="text-[11px] font-bold text-amber-200/70 hover:text-amber-100 mt-2">Ocultar</button>
            </div>
        @endif

        @if($pendientes > 0 && ! $soloPendientes)
            <div class="mb-4 px-4 py-3 rounded-xl bg-brand-500/10 ring-1 ring-brand-300/30 flex items-center justify-between gap-3">
                <span class="text-sm text-brand-200">
                    Hay {{ $pendientes }} cuenta{{ $pendientes === 1 ? '' : 's' }} esperando activación.
                </span>
                <button wire:click="$set('soloPendientes', true)"
                        class="shrink-0 px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                    Ver solo esas
                </button>
            </div>
        @endif

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por nombre…"
                   class="w-full md:w-80 px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
            <label class="flex items-center gap-2 text-sm font-semibold text-ink-700/80 cursor-pointer">
                <input type="checkbox" wire:model.live="soloPendientes" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
                Solo pendientes
            </label>
        </div>

        <div class="space-y-3">
            @forelse($usuarios as $u)
                <div wire:key="user-{{ $u->id }}" class="soft-card p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                        <div class="min-w-0">
                            <p class="font-bold text-ink-800 flex items-center gap-2">
                                {{ $u->name }}
                                @if($u->id === auth()->id())
                                    <span class="px-1.5 py-0.5 rounded bg-cream-200 text-ink-700/70 text-[9px] font-bold uppercase tracking-wider">Tú</span>
                                @endif
                                @unless($u->activo)
                                    <span class="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-300 text-[9px] font-bold uppercase tracking-wider">Pendiente</span>
                                @endunless
                            </p>
                            <p class="text-xs text-ink-700/55 truncate">Se registró el {{ $u->created_at->format('d/m/Y') }}</p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="alternarActivo({{ $u->id }})"
                                    @disabled($u->id === auth()->id())
                                    class="px-3 py-1.5 rounded-lg text-xs font-bold transition
                                           {{ $u->activo
                                                ? 'bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-500/30 hover:bg-emerald-500/20'
                                                : 'bg-cream-100 hover:bg-cream-200 text-ink-800' }}
                                           {{ $u->id === auth()->id() ? 'opacity-40 cursor-not-allowed' : '' }}">
                                {{ $u->activo ? 'Activa' : 'Activar' }}
                            </button>

                            <button wire:click="resetearPassword({{ $u->id }})"
                                    wire:confirm="¿Generar una contraseña temporal para {{ $u->name }}?"
                                    class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                Resetear clave
                            </button>

                            @if($confirmandoId === $u->id)
                                <button wire:click="eliminar({{ $u->id }})"
                                        class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition">
                                    Sí, eliminar
                                </button>
                                <button wire:click="cancelarConfirmacion"
                                        class="px-3 py-1.5 rounded-lg bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                                    Cancelar
                                </button>
                            @else
                                <button wire:click="pedirConfirmacion({{ $u->id }})"
                                        @disabled($u->id === auth()->id())
                                        class="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 ring-1 ring-rose-500/30 text-xs font-bold transition
                                               {{ $u->id === auth()->id() ? 'opacity-40 cursor-not-allowed' : '' }}">
                                    Eliminar
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-3 pt-3 border-t border-cream-200">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-ink-700/45 mb-1.5">Rol</label>
                            <select wire:change="cambiarRol({{ $u->id }}, $event.target.value)"
                                    @disabled($u->id === auth()->id())
                                    class="w-full px-3 py-2 rounded-lg border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300 disabled:opacity-40">
                                @foreach(\App\Models\User::ROLES as $valor => $etiqueta)
                                    <option value="{{ $valor }}" @selected($u->rol === $valor)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-ink-700/45 mb-1.5">Jugador del torneo</label>
                            <select wire:change="vincularJugador({{ $u->id }}, $event.target.value)"
                                    class="w-full px-3 py-2 rounded-lg border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                                <option value="">— sin vincular —</option>
                                @foreach($jugadores as $j)
                                    <option value="{{ $j->id }}" @selected($u->jugador_id === $j->id)>{{ $j->nombre }}</option>
                                @endforeach
                            </select>
                            @if($u->rol === \App\Models\User::ROL_JUGADOR && ! $u->jugador_id)
                                <p class="text-[10px] text-amber-300/80 mt-1">
                                    Sin vincular no podrá publicar resultados.
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="soft-card p-12 text-center">
                    <p class="text-sm font-bold text-ink-800">No hay usuarios que coincidan</p>
                </div>
            @endforelse
        </div>

        <div class="mt-6">{{ $usuarios->links() }}</div>
    </main>
</div>

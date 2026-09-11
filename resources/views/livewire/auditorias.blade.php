<div class="w-full flex items-start">
    @include('partials.sidebar')

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-3xl">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Registro de actividad</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Quién ha tocado qué: resultados, correcciones de partidos, jornadas y cuentas.
            </p>
        </header>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach([
                'todo'      => 'Todo',
                'resultado' => 'Resultados',
                'partido'   => 'Correcciones',
                'jornada'   => 'Jornadas',
                'jugador'   => 'Jugadores',
                'usuario'   => 'Cuentas',
            ] as $valor => $etiqueta)
                <button wire:click="$set('filtro', '{{ $valor }}')"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold transition
                               {{ $filtro === $valor ? 'bg-brand-500 text-white' : 'bg-cream-100 hover:bg-cream-200 text-ink-800' }}">
                    {{ $etiqueta }}
                </button>
            @endforeach
        </div>

        <div class="soft-card p-5">
            <div class="space-y-1">
                @forelse($registros as $r)
                    <div wire:key="aud-{{ $r->id }}"
                         class="flex items-start gap-3 py-2.5 border-b border-cream-200 last:border-0">
                        <span class="w-1.5 h-1.5 rounded-full {{ $r->color }} shrink-0 mt-1.5"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-ink-800">{{ $r->detalle }}</p>
                            <p class="text-[11px] text-ink-700/45 mt-0.5">
                                {{ $r->etiqueta_accion }} · {{ $r->user_nombre }}
                            </p>
                        </div>
                        <span class="text-[10px] font-mono text-ink-700/40 shrink-0 text-right leading-tight">
                            {{ $r->created_at->format('d/m/y') }}<br>{{ $r->created_at->format('H:i') }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-ink-700/50 py-8 text-center">
                        Todavía no hay actividad registrada.
                    </p>
                @endforelse
            </div>
        </div>

        <div class="mt-6">{{ $registros->links() }}</div>
    </main>
</div>

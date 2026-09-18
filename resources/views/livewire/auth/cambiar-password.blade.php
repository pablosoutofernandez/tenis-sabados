<div class="w-full flex items-start">
    {{-- Si la cuenta arrastra la contraseña inicial, el middleware devuelve aquí
         desde cualquier otra pantalla: mejor no pintar un menú que no lleva
         a ninguna parte hasta que la cambie. --}}
    @unless(auth()->user()->debe_cambiar_password)
        @include('partials.sidebar')
    @endunless

    <main class="flex-1 min-w-0 px-4 md:px-8 py-6 max-w-md">

        <header class="mb-6">
            <h1 class="h-display text-3xl text-ink-800">Cambiar contraseña</h1>
            <p class="text-sm text-ink-700/70 mt-1">
                Necesitas la actual para confirmar que eres tú.
            </p>
        </header>

        @if(auth()->user()->debe_cambiar_password)
            <div class="mb-4 px-4 py-3 rounded-xl bg-amber-500/10 text-amber-300 text-sm font-semibold ring-1 ring-amber-500/30">
                Tu cuenta todavía usa la contraseña inicial. Cámbiala para poder seguir.
            </div>
        @endif

        <div class="soft-card p-6">
            <form wire:submit="guardar" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Contraseña actual</label>
                    <input type="password" wire:model="actual" autocomplete="current-password" autofocus
                           class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                    @error('actual')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Contraseña nueva</label>
                    <input type="password" wire:model="password" autocomplete="new-password"
                           class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                    @error('password')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Repite la nueva</label>
                    <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                           class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                    @error('password_confirmation')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                        class="w-full px-5 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition">
                    <span wire:loading.remove wire:target="guardar">Guardar contraseña</span>
                    <span wire:loading wire:target="guardar">Guardando…</span>
                </button>
            </form>
        </div>
    </main>
</div>

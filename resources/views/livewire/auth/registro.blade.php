<div class="soft-card p-6">
    @if($registrado)
        <div class="text-center py-4">
            <div class="text-4xl mb-3">✅</div>
            <h1 class="h-display text-xl text-ink-800 mb-2">Cuenta creada</h1>
            <p class="text-xs text-ink-700/65 leading-relaxed">
                Falta que el administrador active tu cuenta y te vincule con tu ficha de jugador.
                Cuando lo haga, ya podrás entrar.
            </p>
            <a href="{{ route('login') }}" wire:navigate
               class="inline-block mt-5 px-4 py-2 rounded-xl bg-cream-100 hover:bg-cream-200 text-ink-800 text-xs font-bold transition">
                Volver a entrar
            </a>
        </div>
    @else
        <h1 class="h-display text-xl text-ink-800 mb-1">Crear cuenta</h1>
        <p class="text-xs text-ink-700/55 mb-5">El administrador la activará antes de que puedas entrar.</p>

        <form wire:submit="registrarse" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Nombre</label>
                <input type="text" wire:model="name" autocomplete="username" autofocus
                       class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                <p class="text-[10px] text-ink-700/40 mt-1">Es lo que usarás para entrar — tiene que ser único.</p>
                @error('name')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Contraseña</label>
                <input type="password" wire:model="password" autocomplete="new-password"
                       class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
                <p class="text-[10px] text-ink-700/40 mt-1">Mínimo 4 caracteres.</p>
                @error('password')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Repite la contraseña</label>
                <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                       class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
            </div>

            <button type="submit"
                    class="w-full px-5 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition">
                Crear cuenta
            </button>
        </form>

        <p class="text-xs text-ink-700/55 mt-5 text-center">
            ¿Ya tienes cuenta?
            <a href="{{ route('login') }}" wire:navigate class="font-bold text-brand-300 hover:text-white transition">Entrar</a>
        </p>
    @endif
</div>

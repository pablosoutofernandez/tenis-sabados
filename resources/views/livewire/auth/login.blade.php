<div class="soft-card p-6">
    <h1 class="h-display text-xl text-ink-800 mb-1">Entrar</h1>
    <p class="text-xs text-ink-700/55 mb-5">Con tu nombre y contraseña.</p>

    <form wire:submit="entrar" class="space-y-4">
        <div>
            <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Nombre</label>
            <input type="text" wire:model="name" autocomplete="username" autofocus
                   class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
            @error('name')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-xs font-bold text-ink-700/70 mb-1.5">Contraseña</label>
            <input type="password" wire:model="password" autocomplete="current-password"
                   class="w-full px-4 py-2.5 rounded-xl border border-cream-300 bg-cream-100 text-sm focus:outline-none focus:ring-2 focus:ring-brand-300">
            @error('password')<p class="text-[11px] text-rose-400 font-medium mt-1">{{ $message }}</p>@enderror
        </div>

        <label class="flex items-center gap-2 text-xs font-semibold text-ink-700/70 cursor-pointer">
            <input type="checkbox" wire:model="recordarme" class="rounded border-cream-300 text-brand-500 focus:ring-brand-300">
            No cerrar sesión
        </label>

        <button type="submit"
                class="w-full px-5 py-2.5 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white text-sm font-bold shadow-soft hover:from-brand-500 hover:to-brand-700 transition">
            Entrar
        </button>
    </form>

    <p class="text-xs text-ink-700/55 mt-5 text-center">
        ¿Aún no tienes cuenta?
        <a href="{{ route('registro') }}" wire:navigate class="font-bold text-brand-300 hover:text-white transition">Regístrate</a>
    </p>
</div>

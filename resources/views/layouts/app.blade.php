<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('tenis.temporada.nombre') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body x-data="{ menuAbierto: false }" class="bg-cream-50 text-ink-800 antialiased">

    <header class="court-lines bg-cream-100 border-b border-cream-200 relative z-30">
        <div class="max-w-7xl mx-auto px-4 md:px-8 h-16 flex items-center gap-3">
            @auth
                <button @click="menuAbierto = true" aria-label="Abrir menú"
                        class="md:hidden w-9 h-9 -ml-1.5 flex items-center justify-center rounded-lg hover:bg-cream-200 text-ink-800 shrink-0 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            @endauth

            <a href="{{ auth()->check() ? route('inicio') : route('clasificacion') }}" wire:navigate
               class="h-display text-lg text-ink-800 flex-1 md:flex-none">
                🎾 Tenis Sábados
            </a>

            @auth
                <div class="flex items-center gap-3 ml-auto">
                    <span class="text-xs text-ink-700/55 hidden sm:inline">
                        {{ auth()->user()->name }}
                        <span class="text-ink-700/35">· {{ auth()->user()->rol_nombre }}</span>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="px-3 py-1.5 rounded-lg bg-cream-200 hover:bg-cream-300 text-ink-800 text-xs font-bold transition">
                            Salir
                        </button>
                    </form>
                </div>
            @endauth

            @guest
                <a href="{{ route('login') }}" wire:navigate
                   class="ml-auto px-3 py-1.5 rounded-lg bg-cream-200 hover:bg-cream-300 text-ink-800 text-xs font-bold transition">
                    Entrar
                </a>
            @endguest
        </div>
    </header>

    {{ $slot }}

    @livewireScripts
</body>
</html>

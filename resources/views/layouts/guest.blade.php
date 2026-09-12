<!DOCTYPE html>
<html lang="es">
<head>
    @include('partials.head')
</head>
<body class="bg-cream-50 text-ink-800 antialiased">

    <div class="min-h-screen court-lines flex flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('login') }}" wire:navigate class="flex items-center gap-2 h-display text-2xl text-ink-800 mb-6">
            <img src="/logo.svg" alt="" class="w-8 h-8 rounded-lg" width="32" height="32">
            Sábados Tenis
        </a>

        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>

        <p class="text-[11px] text-ink-700/35 mt-6">
            {{ config('tenis.temporada.ciudad') }} · dobles
        </p>
    </div>

    @livewireScripts
</body>
</html>

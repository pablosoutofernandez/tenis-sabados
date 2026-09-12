<!DOCTYPE html>
<html lang="es">
<head>
    @include('partials.head')
</head>
<body class="bg-cream-50 text-ink-800 antialiased">

    <div class="min-h-dvh court-lines flex flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('login') }}" wire:navigate class="flex items-center gap-2 h-display text-2xl text-ink-800 mb-6">
            <img src="/logo.png" alt="" class="w-10 h-10 rounded-lg" width="40" height="40">
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

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('tenis.temporada.nombre') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-cream-50 text-ink-800 antialiased">

    <div class="min-h-screen court-lines flex flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('login') }}" wire:navigate class="h-display text-2xl text-ink-800 mb-6">
            🎾 Tenis Sábados
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

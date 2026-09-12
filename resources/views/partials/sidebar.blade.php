@php
    $usuario = auth()->user();

    // Cada enlace lleva el permiso que hace falta para verlo; los que no
    // aplican al rol (o al visitante sin cuenta) ni se pintan. 'gate' a
    // null significa "cualquier cuenta autenticada, sin permiso especial".
    $enlaces = collect([
        ['ruta' => 'clasificacion',     'texto' => 'Clasificación',      'icono' => '🎾', 'gate' => 'ver-puntuaciones', 'publico' => true],
        ['ruta' => 'jornada.generar',   'texto' => 'Montar jornada',     'icono' => '🗓️', 'gate' => 'gestionar-jornadas'],
        ['ruta' => 'historial',         'texto' => 'Historial',          'icono' => '📋', 'gate' => null],
        ['ruta' => 'mis-estadisticas',  'texto' => 'Mis estadísticas',   'icono' => '📈', 'gate' => null],
        ['ruta' => 'jugadores',         'texto' => 'Jugadores',          'icono' => '👥', 'gate' => 'gestionar-jugadores'],
        ['ruta' => 'ajustes-ia',        'texto' => 'Ajustes',            'icono' => '🎛️', 'gate' => 'gestionar-ajustes'],
        ['ruta' => 'usuarios',          'texto' => 'Usuarios',           'icono' => '🔑', 'gate' => 'gestionar-usuarios'],
        ['ruta' => 'auditoria',         'texto' => 'Actividad',          'icono' => '📜', 'gate' => 'ver-auditoria'],
    ])->filter(function ($e) use ($usuario) {
        if (! $usuario) {
            return $e['publico'] ?? false;
        }

        return $e['gate'] === null || $usuario->can($e['gate']);
    });
@endphp

{{-- Fondo oscuro al abrir el menú en móvil --}}
<div x-show="menuAbierto" x-transition.opacity @click="menuAbierto = false"
     class="md:hidden fixed inset-0 bg-ink-800/50 z-40" style="display: none;"></div>

<aside
    x-cloak
    :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'"
    class="md:translate-x-0 fixed md:static inset-y-0 left-0 z-50 flex flex-col w-64 md:w-56 shrink-0
           min-h-screen md:min-h-[calc(100vh-64px)] border-r border-cream-200 bg-cream-50 md:bg-cream-50/40
           px-3 py-6 transition-transform duration-200 ease-out overflow-y-auto">

    <div class="flex items-center justify-between mb-4 px-3">
        <p class="text-[11px] font-bold text-ink-700/45 leading-snug">
            Tenis Sábados<br>
            <span class="text-ink-700/30">{{ config('tenis.temporada.ciudad') }} · dobles</span>
        </p>
        <button @click="menuAbierto = false" aria-label="Cerrar menú"
                class="md:hidden w-8 h-8 flex items-center justify-center rounded-lg hover:bg-cream-200 text-ink-700/60 shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <nav class="space-y-1">
        @foreach($enlaces as $enlace)
            @php $activo = request()->routeIs($enlace['ruta']); @endphp
            <a href="{{ route($enlace['ruta']) }}" wire:navigate @click="menuAbierto = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors
                      {{ $activo ? 'bg-cream-100 ring-2 ring-brand-300/50 text-brand-300' : 'text-ink-700/70 hover:bg-cream-100/70' }}">
                <span class="text-base">{{ $enlace['icono'] }}</span>
                {{ $enlace['texto'] }}
            </a>
        @endforeach
    </nav>

    <div class="mt-auto px-3 pt-4 border-t border-cream-200">
        @if($usuario)
            <p class="text-xs font-bold text-ink-800 truncate">{{ $usuario->name }}</p>
            <p class="text-[10px] text-ink-700/45 mb-2">
                {{ $usuario->rol_nombre }}@if($usuario->jugador) · {{ $usuario->jugador->nombre }}@endif
            </p>
            <a href="{{ route('password.cambiar') }}" wire:navigate @click="menuAbierto = false"
               class="block text-[11px] font-bold text-ink-700/55 hover:text-ink-800 transition">Cambiar contraseña</a>
            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit" class="text-[11px] font-bold text-ink-700/55 hover:text-rose-300 transition">
                    Cerrar sesión
                </button>
            </form>
        @else
            <p class="text-[10px] text-ink-700/45 mb-2 leading-relaxed">
                Entra para anotar resultados o montar jornadas.
            </p>
            <a href="{{ route('login') }}" wire:navigate
               class="block text-[11px] font-bold text-brand-300 hover:text-white transition">Entrar</a>
        @endif
    </div>
</aside>

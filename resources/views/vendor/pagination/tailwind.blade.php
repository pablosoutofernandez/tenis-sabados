@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Paginación" class="flex items-center justify-between gap-4">
        <div class="flex-1 flex justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 rounded-lg bg-cream-100 text-ink-700/30 text-xs font-bold cursor-default">Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" wire:navigate
                   class="px-3 py-1.5 rounded-lg bg-cream-100 text-ink-800 text-xs font-bold hover:bg-cream-200 transition">Anterior</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" wire:navigate
                   class="px-3 py-1.5 rounded-lg bg-cream-100 text-ink-800 text-xs font-bold hover:bg-cream-200 transition">Siguiente</a>
            @else
                <span class="px-3 py-1.5 rounded-lg bg-cream-100 text-ink-700/30 text-xs font-bold cursor-default">Siguiente</span>
            @endif
        </div>

        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <p class="text-xs text-ink-700/55">
                {{ __('Mostrando') }}
                <span class="font-bold text-ink-700">{{ $paginator->firstItem() }}</span>
                {{ __('a') }}
                <span class="font-bold text-ink-700">{{ $paginator->lastItem() }}</span>
                {{ __('de') }}
                <span class="font-bold text-ink-700">{{ $paginator->total() }}</span>
            </p>

            <div class="flex items-center gap-1">
                {{-- Anterior --}}
                @if ($paginator->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-700/25 cursor-default">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" wire:navigate
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-700/70 hover:bg-cream-100 hover:text-ink-800 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                @endif

                {{-- Números --}}
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="w-8 h-8 flex items-center justify-center text-xs text-ink-700/40">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="w-8 h-8 flex items-center justify-center rounded-lg bg-brand-500 text-white text-xs font-bold">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" wire:navigate
                                   class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-bold text-ink-700/70 hover:bg-cream-100 hover:text-ink-800 transition">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Siguiente --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" wire:navigate
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-700/70 hover:bg-cream-100 hover:text-ink-800 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-700/25 cursor-default">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </span>
                @endif
            </div>
        </div>
    </nav>
@endif

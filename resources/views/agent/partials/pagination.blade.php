{{-- Paginación simple (sin Tailwind). Requiere $paginator. --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Paginación">
        @if ($paginator->onFirstPage())
            <span class="pager__link is-disabled">Anterior</span>
        @else
            <a class="pager__link" href="{{ $paginator->previousPageUrl() }}">Anterior</a>
        @endif

        <span class="pager__info">
            Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }} · {{ $paginator->total() }} registros
        </span>

        @if ($paginator->hasMorePages())
            <a class="pager__link" href="{{ $paginator->nextPageUrl() }}">Siguiente</a>
        @else
            <span class="pager__link is-disabled">Siguiente</span>
        @endif
    </nav>
@endif

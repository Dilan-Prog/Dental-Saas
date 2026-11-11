{{-- Paginación simple (anterior/siguiente) con el CSS de la app. --}}
@if ($paginator->hasPages())
    <nav class="pagination-wrap" role="navigation" aria-label="Paginación">
        <div class="pagination">
            @if ($paginator->onFirstPage())
                <span class="page is-disabled">&lsaquo; Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="page">&lsaquo; Anterior</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="page">Siguiente &rsaquo;</a>
            @else
                <span class="page is-disabled">Siguiente &rsaquo;</span>
            @endif
        </div>
    </nav>
@endif

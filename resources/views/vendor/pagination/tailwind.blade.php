{{-- Paginación en español con el CSS de la app. Reemplaza la vista por defecto de Laravel ("pagination::tailwind"). --}}
@if ($paginator->hasPages())
    <nav class="pagination-wrap" role="navigation" aria-label="Paginación">
        <div class="text-muted text-sm">
            Mostrando <span class="fw-600">{{ $paginator->firstItem() }}</span> a <span class="fw-600">{{ $paginator->lastItem() }}</span>
            de <span class="fw-600">{{ $paginator->total() }}</span> resultados
        </div>

        <div class="pagination">
            @if ($paginator->onFirstPage())
                <span class="page is-disabled" aria-disabled="true">&lsaquo; Anterior</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="page">&lsaquo; Anterior</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="page is-disabled">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="page is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="page" aria-label="Ir a la página {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="page">Siguiente &rsaquo;</a>
            @else
                <span class="page is-disabled" aria-disabled="true">Siguiente &rsaquo;</span>
            @endif
        </div>
    </nav>
@endif

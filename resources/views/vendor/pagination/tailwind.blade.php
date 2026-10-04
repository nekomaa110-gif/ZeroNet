@if ($paginator->hasPages())
  <nav class="pgnt" role="navigation" aria-label="{{ __('Pagination Navigation') }}">

    <div class="pgnt-mobile">
      @if ($paginator->onFirstPage())
        <span class="btn pgnt-arrow is-disabled" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </span>
      @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn pgnt-arrow" aria-label="{{ __('pagination.previous') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
      @endif

      <span class="pgnt-info">
        Hal <b>{{ $paginator->currentPage() }}</b> dari <b>{{ $paginator->lastPage() }}</b>
      </span>

      @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn pgnt-arrow" aria-label="{{ __('pagination.next') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      @else
        <span class="btn pgnt-arrow is-disabled" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
      @endif
    </div>

    <div class="pgnt-desktop">
      @if ($paginator->onFirstPage())
        <span class="pgnt-btn is-disabled" aria-disabled="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </span>
      @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pgnt-btn" aria-label="{{ __('pagination.previous') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
      @endif

      @foreach ($elements as $element)
        @if (is_string($element))
          <span class="pgnt-btn is-dots" aria-disabled="true">{{ $element }}</span>
        @endif
        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span class="pgnt-btn is-current" aria-current="page">{{ $page }}</span>
            @else
              <a href="{{ $url }}" class="pgnt-btn">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach

      @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pgnt-btn" aria-label="{{ __('pagination.next') }}">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
      @else
        <span class="pgnt-btn is-disabled" aria-disabled="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
      @endif
    </div>
  </nav>
@endif

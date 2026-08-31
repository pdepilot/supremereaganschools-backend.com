@if($paginator->total() > 0)
  <nav class="blog-index-pager mt-5" aria-label="{{ $label ?? 'Pages' }}">
    <p class="blog-pager-count">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>
    @if($paginator->hasPages())
      {{ $paginator->onEachSide(1)->links('pagination::bootstrap-5') }}
    @endif
  </nav>
@endif

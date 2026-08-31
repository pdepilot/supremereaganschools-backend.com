<aside class="col-lg-4">
  <div class="surface-panel sticky-panel">
    @if(($categories ?? collect())->isNotEmpty())
      <h2 class="blog-side-title">Categories</h2>
      <nav class="news-cats mb-4" aria-label="Article categories">
        <a href="{{ url('/news') }}" class="{{ request()->is('news') && ! request()->filled('q') && ! request()->filled('tag') ? 'is-on' : '' }}">All</a>
        @foreach($categories as $category)
          <a href="{{ url('/news/'.$category->slug) }}" class="{{ request()->is('news/'.$category->slug) ? 'is-on' : '' }}">{{ $category->name }}</a>
        @endforeach
      </nav>
    @endif

    <h2 class="blog-side-title">Popular content</h2>
    @if(($popular ?? collect())->isNotEmpty())
      <ul class="blog-mini-list">
        @foreach($popular as $item)
          <li>
            <a href="{{ $item->publicUrl() }}">{{ $item->title }}</a>
            <span>{{ max(1, (int) $item->reading_time) }} min · {{ $item->viewsCount() }} {{ \Illuminate\Support\Str::plural('view', $item->viewsCount()) }}</span>
          </li>
        @endforeach
      </ul>
    @else
      <p class="mb-0 text-muted small">Popular articles will appear as the house publishes.</p>
    @endif

    @if(($editorsPicks ?? collect())->isNotEmpty())
      <h2 class="blog-side-title mt-4">Editors’ picks</h2>
      <ul class="blog-mini-list">
        @foreach($editorsPicks as $item)
          <li>
            <a href="{{ $item->publicUrl() }}">{{ $item->title }}</a>
            <span>{{ $item->published_at?->timezone('Africa/Lagos')->format('M j, Y') }}</span>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</aside>

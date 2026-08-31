@php
  $cover = $article->featuredImageUrl() ?: url('/site/Image/class_pics1.jpg');
@endphp
<article class="blog-card h-100">
  <a href="{{ $article->publicUrl() }}" class="blog-card-media">
    <img src="{{ $cover }}" alt="{{ $article->featured_image_alt ?: $article->title }}" loading="{{ $loading ?? 'lazy' }}">
  </a>
  <div class="blog-card-body">
    <p class="blog-card-meta">
      {{ $article->published_at?->timezone('Africa/Lagos')->format('M j, Y') }}
      · {{ max(1, (int) $article->reading_time) }} min read
      · {{ $article->viewsCount() }} {{ \Illuminate\Support\Str::plural('view', $article->viewsCount()) }}
      · {{ $article->authorName() }}
    </p>
    <h3 class="blog-card-title">
      <a href="{{ $article->publicUrl() }}">{{ $article->title }}</a>
    </h3>
    <p class="blog-card-excerpt">{{ $article->excerpt }}</p>
    <a class="btn btn-secondary py-2 px-4" href="{{ $article->publicUrl() }}">Read more</a>
  </div>
</article>

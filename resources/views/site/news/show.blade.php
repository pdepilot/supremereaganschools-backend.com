@extends('site.layout')

@section('title', $article->meta_title ?: $article->title)
@section('meta_description', $article->meta_description ?: $article->excerpt)
@section('canonical', $article->canonicalUrlResolved())
@section('og_type', 'article')
@section('og_title', $article->og_title ?: ($article->meta_title ?: $article->title))
@section('og_description', $article->og_description ?: ($article->meta_description ?: $article->excerpt))
@section('og_image', $article->ogImageResolved() ?: url('/site/Image/logo_main.png'))
@section('body_class', 'news-article')

@section('content')
  @include('site.partials.news-hero', [
    'title' => $article->title,
    'heroImage' => $article->featuredImageUrl() ?: '/site/Image/class_pics1.jpg',
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'News & Insights', 'url' => url('/news')],
      ['label' => 'Article'],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
    <div class="container">
      <div class="row g-4 g-xl-5">
        <aside class="col-lg-3 order-2 order-lg-1">
          <div class="blog-side-stack">
            @if(! empty($toc))
              <nav class="blog-toc surface-panel" aria-label="Table of contents">
                <h2 class="blog-side-title">On this page</h2>
                <ol>
                  @foreach($toc as $item)
                    <li class="toc-level-{{ $item['level'] }}">
                      <a href="#{{ $item['id'] }}">{{ $item['text'] }}</a>
                    </li>
                  @endforeach
                </ol>
              </nav>
            @endif

            @if(($popular ?? collect())->isNotEmpty())
              <div class="surface-panel">
                <h2 class="blog-side-title">Popular reads</h2>
                <ul class="blog-mini-list">
                  @foreach($popular as $item)
                    <li>
                      <a href="{{ $item->publicUrl() }}">{{ $item->title }}</a>
                      <span>{{ max(1, (int) $item->reading_time) }} min · {{ $item->viewsCount() }} {{ \Illuminate\Support\Str::plural('view', $item->viewsCount()) }}</span>
                    </li>
                  @endforeach
                </ul>
              </div>
            @endif

            <x-ad-slot position="article_top" />
          </div>
        </aside>

        <div class="col-lg-7 order-1 order-lg-2">
          <article class="blog-article" data-article-view data-article-slug="{{ $article->slug }}" id="blogArticle">
            @if(!empty($preview))
              <p class="news-preview-banner">Preview — this article is not public. Search engines will not index this page.</p>
            @endif

            @if($article->featuredImageUrl())
              <img class="blog-article-cover mb-4" src="{{ $article->featuredImageUrl() }}" alt="{{ $article->featured_image_alt ?: $article->title }}" loading="eager">
            @endif

            <div class="blog-article-meta mb-4">
              <p class="blog-card-meta mb-2">
                Published: <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->timezone('Africa/Lagos')->format('F j, Y') ?? '—' }}</time>
                @if($article->authorPublicUrl())
                  · <a href="{{ $article->authorPublicUrl() }}">{{ $article->authorName() }}</a>
                @else
                  · {{ $article->authorName() }}
                @endif
                · {{ max(1, (int) $article->reading_time) }} min read
                · {{ $article->viewsCount() }} {{ \Illuminate\Support\Str::plural('view', $article->viewsCount()) }}
                @if($article->wasMateriallyUpdated())
                  · Updated: <time datetime="{{ $article->updated_at?->toIso8601String() }}">{{ $article->updated_at?->timezone('Africa/Lagos')->format('j F Y') }}</time>
                @endif
              </p>
              @if($article->tags->isNotEmpty())
                <div class="blog-tags">
                  @foreach($article->tags as $tag)
                    <a href="{{ url('/news?tag='.$tag->slug) }}">{{ $tag->name }}</a>
                  @endforeach
                </div>
              @endif
            </div>

            <div class="blog-article-body article-body" id="articleBody">
              @php
                $articleParts = preg_split('/<\/p>/i', (string) ($articleHtml ?? $article->content), 2);
              @endphp
              @if(is_array($articleParts) && count($articleParts) === 2)
                {!! $articleParts[0].'</p>' !!}
                <x-ad-slot position="article_middle" />
                {!! $articleParts[1] !!}
              @else
                {!! $articleHtml ?? $article->content !!}
              @endif
            </div>

            @if($article->hasDownloadableResource() && $article->isPubliclyVisible())
              <p class="article-download mt-4"><a href="{{ $article->resourceDownloadUrl() }}">Download the accompanying resource{{ $article->resource_original_name ? ' ('.$article->resource_original_name.')' : '' }}</a></p>
            @endif

            <x-ad-slot position="article_bottom" />

            <x-school-cta :article="$article" />

            <nav class="blog-pager mt-5" aria-label="Nearby articles">
              <div>
                @if($previous)
                  <a href="{{ $previous->publicUrl() }}"><span>Previous</span><strong>{{ $previous->title }}</strong></a>
                @endif
              </div>
              <div class="text-lg-end">
                @if($next)
                  <a href="{{ $next->publicUrl() }}"><span>Next</span><strong>{{ $next->title }}</strong></a>
                @endif
              </div>
            </nav>

            <div class="mt-4">
              <a href="{{ url('/news') }}" class="btn btn-primary py-2 px-4">← Back to news</a>
            </div>

            <p class="article-internal mt-4">
              Related school pages:
              <a href="/about">About</a>,
              <a href="/resources">Resources</a>,
              <a href="/admissions">Admissions</a>,
              <a href="/contact">Contact</a>.
            </p>
          </article>
        </div>

        <aside class="col-lg-2 order-3">
          <div class="blog-share sticky-share" aria-label="Share this article">
            <span class="blog-side-title">Share</span>
            @php $share = rawurlencode($article->publicUrl()); $text = rawurlencode($article->title); @endphp
            <a class="share-btn" href="https://www.facebook.com/sharer/sharer.php?u={{ $share }}" rel="noopener noreferrer" target="_blank" aria-label="Share on Facebook"><i class="bi bi-facebook"></i></a>
            <a class="share-btn" href="https://api.whatsapp.com/send?text={{ $text }}%20{{ $share }}" rel="noopener noreferrer" target="_blank" aria-label="Share on WhatsApp"><i class="bi bi-whatsapp"></i></a>
            <a class="share-btn" href="https://twitter.com/intent/tweet?url={{ $share }}&text={{ $text }}" rel="noopener noreferrer" target="_blank" aria-label="Share on X"><i class="bi bi-twitter-x"></i></a>
            <a class="share-btn" href="https://www.linkedin.com/sharing/share-offsite/?url={{ $share }}" rel="noopener noreferrer" target="_blank" aria-label="Share on LinkedIn"><i class="bi bi-linkedin"></i></a>
          </div>
        </aside>
      </div>

      @if($related->isNotEmpty())
        <div class="mt-5 pt-4">
          <h2 class="mb-4">Related from the house</h2>
          <div class="row g-4">
            @foreach($related as $item)
              <div class="col-lg-4 col-md-6">
                @include('site.partials.news-card', ['article' => $item])
              </div>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
@endsection

@push('jsonld')
  @php
    $publisher = [
      '@type' => 'EducationalOrganization',
      'name' => \App\Support\SchoolIdentity::name(),
      'url' => url('/'),
      'logo' => [
        '@type' => 'ImageObject',
        'url' => \App\Support\SchoolIdentity::logoUrl(),
      ],
    ];
    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $article->title,
      'description' => $article->meta_description ?: $article->excerpt,
      'author' => [
        '@type' => 'Person',
        'name' => $article->authorName(),
      ],
      'publisher' => $publisher,
      'datePublished' => $article->published_at?->toIso8601String(),
      'dateModified' => $article->updated_at?->toIso8601String(),
      'mainEntityOfPage' => $article->canonicalUrlResolved(),
    ];
    if ($article->ogImageResolved()) {
      $schema['image'] = [$article->ogImageResolved()];
    }
    $crumbs = [
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      'itemListElement' => array_values(array_filter([
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'News & Insights', 'item' => url('/news')],
        $article->category ? ['@type' => 'ListItem', 'position' => 3, 'name' => $article->category->name, 'item' => url('/news/'.$article->category->slug)] : null,
        ['@type' => 'ListItem', 'position' => $article->category ? 4 : 3, 'name' => $article->title, 'item' => $article->canonicalUrlResolved()],
      ])),
    ];
  @endphp
  <script type="application/ld+json">@json($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
  <script type="application/ld+json">@json($crumbs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endpush

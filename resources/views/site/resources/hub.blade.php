@extends('site.layout')

@section('title', $hub->meta_title ?: $hub->name)
@section('meta_description', $hub->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($hub->intro), 155, ''))
@section('canonical', $hub->publicUrl())
@section('body_class', 'resource-hub')

@section('content')
  @include('site.partials.news-hero', [
    'title' => $hub->name,
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'Resources', 'url' => url('/resources')],
      ['label' => $hub->name],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
  <div class="container news-wrap">
  @if(filled($hub->intro))
    <div class="text-center mx-auto mb-5" style="max-width: 640px;">
      <p class="section-title bg-white text-center text-primary px-3">{{ $hub->kicker ?: 'Education resource' }}</p>
      <div class="text-muted">{!! nl2br(e($hub->intro)) !!}</div>
    </div>
  @endif
  <x-ad-slot position="hub_top" />

  @if($hub->is_parent_hub)
    <section class="parent-groups" aria-label="Parent resource groups">
      <article>
        <h2>Academic support</h2>
        <p>Helping children study, homework, reading, and examination preparation.</p>
      </article>
      <article>
        <h2>Child development</h2>
        <p>Confidence, leadership, discipline, and communication at home.</p>
      </article>
      <article>
        <h2>School decisions</h2>
        <p>Choosing a school, questions to ask, and preparing a child for transition.</p>
      </article>
      <article>
        <h2>Digital life</h2>
        <p>Responsible technology use, digital literacy, and online safety.</p>
      </article>
    </section>
  @endif

  @if($featured->isNotEmpty())
    <section class="news-related" aria-label="Featured resources">
      <h2>Featured in this hub</h2>
      <div class="news-grid">
        @foreach($featured as $article)
          <article class="news-card">
            <a href="{{ $article->publicUrl() }}">
              <p class="news-cat">{{ $article->content_type?->label() }} · {{ $article->category?->name }}</p>
              <h3>{{ $article->title }}</h3>
              <p>{{ $article->excerpt }}</p>
            </a>
          </article>
        @endforeach
      </div>
    </section>
  @endif

  <x-ad-slot position="hub_between" />

  <section class="news-grid" aria-label="Latest in this hub">
    @forelse($latest as $article)
      <article class="news-card">
        <a href="{{ $article->publicUrl() }}">
          @if($article->featuredImageUrl())
            <img src="{{ $article->featuredImageUrl() }}" alt="{{ $article->featured_image_alt ?: $article->title }}" loading="lazy">
          @endif
          <p class="news-cat">{{ $article->content_type?->label() }}</p>
          <h2>{{ $article->title }}</h2>
          <p>{{ $article->excerpt }}</p>
          <p class="news-meta">{{ $article->published_at?->timezone('Africa/Lagos')->format('j F Y') }}</p>
        </a>
      </article>
    @empty
      @if($featured->isEmpty())
        <p class="news-empty">This hub will be indexed when the house has published enough original guidance here. Meanwhile see <a href="/about">About</a>, <a href="/admissions">Admissions</a>, or <a href="/news">News &amp; Insights</a>.</p>
      @endif
    @endforelse
  </section>

  @if($categories->isNotEmpty())
    <nav class="news-cats" aria-label="Related categories">
      @foreach($categories as $category)
        <a href="{{ url('/news/'.$category->slug) }}">{{ $category->name }}</a>
      @endforeach
      <a href="/resources">All hubs</a>
      <a href="/news">News &amp; Insights</a>
    </nav>
  @endif

  <x-school-cta :type="$hub->cta_type" strength="standard" />
  </div>
  </div>
@endsection

@push('jsonld')
  @php
    $crumbs = [
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Resources', 'item' => url('/resources')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $hub->name, 'item' => $hub->publicUrl()],
      ],
    ];
  @endphp
  <script type="application/ld+json">@json($crumbs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endpush

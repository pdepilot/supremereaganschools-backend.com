@extends('site.layout')

@section('title', $category->meta_title ?: $category->name)
@section('meta_description', $category->meta_description ?: ($category->description ?: 'Articles on '.$category->name.' from Supreme Reagan Schools.'))
@section('canonical', url('/news/'.$category->slug))
@section('body_class', 'news-category')

@section('content')
  @include('site.partials.news-hero', [
    'title' => $category->name,
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'News & Insights', 'url' => url('/news')],
      ['label' => $category->name],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
    <div class="container">
      <div class="text-center mx-auto mb-5" style="max-width: 620px;">
        <p class="section-title bg-white text-center text-primary px-3">Category</p>
        <h2 class="mb-3">{{ $category->description ?: 'Writing from the house on '.$category->name.'.' }}</h2>
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="row g-4">
            @forelse($articles as $article)
              <div class="col-md-6">
                @include('site.partials.news-card', ['article' => $article])
              </div>
            @empty
              <div class="col-12 text-center py-5">
                <p class="mb-0 text-muted news-empty">There are no published articles in this category yet. Return to <a href="/news">News &amp; Insights</a>.</p>
              </div>
            @endforelse
          </div>

          @include('site.partials.news-pager', ['paginator' => $articles, 'label' => $category->name.' pages'])
        </div>

        @include('site.partials.news-sidebar', [
          'popular' => $popular,
          'editorsPicks' => $editorsPicks,
        ])
      </div>
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
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'News & Insights', 'item' => url('/news')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $category->name, 'item' => url('/news/'.$category->slug)],
      ],
    ];
  @endphp
  <script type="application/ld+json">@json($crumbs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endpush

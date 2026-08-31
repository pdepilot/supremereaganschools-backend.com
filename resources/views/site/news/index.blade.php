@extends('site.layout')

@section('title', $searching ? 'Search News & Insights' : 'News & Insights')
@section('meta_description', $searching
    ? 'Search results from Supreme Reagan Schools News & Insights.'
    : 'School news, parenting guidance, academic resources, and life at Supreme Reagan Schools.')
@section('canonical', $searching ? url('/news') : url('/news'))
@section('body_class', 'news-index')

@section('content')
  @include('site.partials.news-hero', [
    'title' => 'News & Insights',
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'News & Insights'],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
    <div class="container">
      <div class="text-center mx-auto mb-4" style="max-width: 620px;">
        <p class="section-title bg-white text-center text-primary px-3">The house journal</p>
        <h2 class="mb-3">Guidance for parents, pupils, and the wider house</h2>
      </div>

      <div class="blog-search-wrap mb-5">
        <form action="{{ url('/news') }}" method="get" class="blog-search-form" role="search" autocomplete="off">
          <label class="visually-hidden" for="newsQuery">Search articles</label>
          <input id="newsQuery" type="search" name="q" value="{{ $query }}" placeholder="Search study habits, WAEC, admissions…">
          <button type="submit" class="btn btn-primary">Search</button>
        </form>
        @if($searching)
          <p class="blog-search-hint mt-2 mb-0">Showing results for “{{ $query !== '' ? $query : request('tag') }}”. Search pages are not indexed. <a href="{{ url('/news') }}">Clear</a></p>
        @endif
      </div>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="row g-4">
            @forelse($articles as $article)
              <div class="col-md-6">
                @include('site.partials.news-card', ['article' => $article, 'loading' => $loop->first ? 'eager' : 'lazy'])
              </div>
            @empty
              <div class="col-12 text-center py-5">
                <p class="mb-0 text-muted news-empty">
                  @if($searching)
                    No articles matched “{{ $query !== '' ? $query : request('tag') }}”.
                  @else
                    The house has not published articles in this view yet. Visit <a href="/about">About</a> or <a href="/admissions">Admissions</a> while the journal is prepared.
                  @endif
                </p>
              </div>
            @endforelse
          </div>

          <x-ad-slot position="archive_between_posts" />

          @include('site.partials.news-pager', ['paginator' => $articles, 'label' => 'News pages'])
        </div>

        @include('site.partials.news-sidebar', [
          'categories' => $categories,
          'popular' => $popular,
          'editorsPicks' => $editorsPicks,
        ])
      </div>
    </div>
  </div>
@endsection

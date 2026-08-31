@extends('site.layout')

@section('title', 'Education & Parent Resources')
@section('meta_description', 'Study guidance, parenting notes, examination preparation, and student development from Supreme Reagan Schools — written for families, not as a magazine of slogans.')
@section('canonical', url('/resources'))
@section('body_class', 'resource-hub')

@section('content')
  @include('site.partials.news-hero', [
    'title' => 'Resources',
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'Resources'],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
  <div class="container news-wrap">
  <div class="text-center mx-auto mb-5" style="max-width: 640px;">
    <p class="section-title bg-white text-center text-primary px-3">Education &amp; parent resource hub</p>
    <h2 class="mb-3">Help first. Then the house, if you wish to know us.</h2>
    <p class="text-muted">These pages collect the school’s public guidance for families: study, parenting, examinations, and the life of the child. A hub is indexed only when it already holds real published writing.</p>
  </div>
  <x-ad-slot position="hub_between" />

  <section class="hub-grid" aria-label="Resource hubs">
    @foreach($hubs as $hub)
      <article class="news-card">
        <a href="{{ $hub->publicUrl() }}">
          <p class="news-cat">{{ $hub->kicker ?: 'Resource hub' }}</p>
          <h2>{{ $hub->name }}</h2>
          <p>{{ \Illuminate\Support\Str::limit(strip_tags($hub->intro), 180) }}</p>
          <p class="news-meta">{{ $hub->publishedCount() }} published {{ \Illuminate\Support\Str::plural('note', $hub->publishedCount()) }}</p>
        </a>
      </article>
    @endforeach
  </section>

  @if($parents->isNotEmpty())
    <section class="news-related" aria-label="Parent resources">
      <h2>Parent resources</h2>
      <p class="news-lead">Notes that help a household with study, discipline, reading, and school decisions — then a calm path to the school if you need it.</p>
      <div class="news-grid">
        @foreach($parents as $article)
          <article class="news-card">
            <a href="{{ $article->publicUrl() }}">
              <p class="news-cat">{{ $article->category?->name }}</p>
              <h3>{{ $article->title }}</h3>
              <p>{{ $article->excerpt }}</p>
            </a>
          </article>
        @endforeach
      </div>
    </section>
  @endif

  <aside class="school-cta school-cta-standard" aria-label="School">
    <p class="school-cta-kicker">The house</p>
    <h2>Supreme Reagan Schools</h2>
    <p>If a page here answered a question, you may also read who the school is, how the rooms teach, and how a child joins.</p>
    <nav class="school-cta-links">
      <a href="/about">About</a>
      <a href="/admissions">Admissions</a>
      <a href="/contact">Contact</a>
      <a href="/news">News &amp; Insights</a>
    </nav>
  </aside>
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
      ],
    ];
  @endphp
  <script type="application/ld+json">@json($crumbs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endpush

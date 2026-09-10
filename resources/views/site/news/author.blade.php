@extends('site.layout')

@php
  $publicRole = trim((string) ($author->authorProfile?->public_role ?? ''));
  $profileBio = trim((string) ($author->authorProfile?->biography ?? ''));
  $profilePhoto = $author->authorProfile?->photoUrl();
  $staffPhoto = filled($author->staffProfile?->photo_path) ? url($author->staffProfile->photo_path) : null;
  $photo = $profilePhoto ?: $staffPhoto;

  $role = $publicRole !== ''
      ? $publicRole
      : trim((string) ($author->staffProfile?->job_title ?? ''));
  if ($role === '') {
      $role = 'Supreme Reagan Schools Editorial Team';
  }

  $bio = $profileBio !== ''
      ? $profileBio
      : 'Writing from the Supreme Reagan Schools editorial desk. This page names the author of published public notes. It does not invent qualifications.';

  // Person schema only when the profile holds school-supplied facts beyond fallbacks.
  $hasFactualPersonSchema = $publicRole !== '' || $profileBio !== '' || filled($profilePhoto);
@endphp

@section('title', $author->name)
@section('meta_description', $role.' — public notes from '.$author->name.' at Supreme Reagan Schools.')
@section('canonical', url('/news/authors/'.$author->id))
@section('body_class', 'news-author')

@section('content')
  @include('site.partials.news-hero', [
    'title' => $author->name,
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'News & Insights', 'url' => url('/news')],
      ['label' => $author->name],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
    <div class="container">
      <div class="text-center mx-auto mb-5" style="max-width: 620px;">
        @if($photo)
          <img class="news-page-author-photo" src="{{ $photo }}" alt="{{ $author->name }}" width="96" height="96">
        @endif
        <p class="section-title bg-white text-center text-primary px-3">Author</p>
        <h2 class="mb-3">{{ $role }}</h2>
        <p class="text-muted">{{ $bio }}</p>
      </div>

      <div class="row g-4">
        @foreach($articles as $article)
          <div class="col-md-6 col-lg-4">
            @include('site.partials.news-card', ['article' => $article])
          </div>
        @endforeach
      </div>

      @include('site.partials.news-pager', ['paginator' => $articles, 'label' => 'Articles by '.$author->name])
    </div>
  </div>
@endsection

@push('jsonld')
  @if($hasFactualPersonSchema)
    @php
      $person = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $author->name,
        'url' => url('/news/authors/'.$author->id),
      ];
      if ($publicRole !== '') {
          $person['jobTitle'] = $publicRole;
      }
      if ($profileBio !== '') {
          $person['description'] = $profileBio;
      }
      if (filled($profilePhoto)) {
          $person['image'] = $profilePhoto;
      }
    @endphp
    <script type="application/ld+json">@json($person, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
  @endif
@endpush

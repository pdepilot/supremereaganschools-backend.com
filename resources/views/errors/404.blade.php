@extends('site.layout')

@section('title', 'Page not found')
@section('meta_description', 'That page is not on the Supreme Reagan Schools website.')
@section('canonical', url('/'))
@section('body_class', 'error-page')

@php $indexable = false; @endphp

@section('content')
  <article class="legal-doc">
    <p class="news-kicker">The house</p>
    <h1>This page is not here</h1>
    <p>The address you opened is not a public page of Supreme Reagan Schools. If you followed a link to an old article, it may have been removed rather than quietly replaced.</p>
    <p>You can return to the <a href="/">home page</a>, read <a href="/news">News &amp; Insights</a>, see <a href="/admissions">admissions</a>, or <a href="/contact">contact the office</a>.</p>
  </article>
@endsection

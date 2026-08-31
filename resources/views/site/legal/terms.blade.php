@extends('site.layout')

@section('title', 'Terms of Use')
@section('meta_description', 'Terms for using the Supreme Reagan Schools website, News & Insights, and school desks.')
@section('canonical', url('/terms'))
@section('body_class', 'legal-page')

@section('content')
  @include('site.partials.news-hero', [
    'title' => 'Terms of Use',
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'Terms of Use'],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
  <article class="legal-doc">
    <p class="legal-updated">Last reviewed: 29 August 2026. These terms describe how the public website and school desks may be used. They are not a contract of admission and do not replace the school’s admission letter or handbook.</p>

    <h2>The website</h2>
    <p>{{ \App\Support\SchoolIdentity::name() }} publishes this site so families can learn about the house, apply, read News &amp; Insights, and — if they have been given an account — use a staff, parent, or pupil desk.</p>

    <h2>Public pages</h2>
    <p>Articles, programme pages, and notices on the public site are for information. They are not legal advice, medical advice, or a promise of a particular examination result. Admission depends on the school’s process, available places, and the documents the office requests.</p>

    <h2>School desks</h2>
    <p>Staff, parent, and pupil desks are private. You may use only the account issued to you. Do not share login details. Do not attempt to open another family’s records. The school may suspend an account that is misused.</p>

    <h2>Your submissions</h2>
    <p>Contact forms, admission forms, and messages you send must be truthful. Do not upload unlawful material or files that you do not have the right to send. The school may refuse or delete a submission that cannot be handled safely.</p>

    <h2>Intellectual property</h2>
    <p>The school’s name, crest, photographs, and original articles belong to the house unless a credit says otherwise. You may share a public article using the provided links. You may not copy the site to run a look-alike school page or scrape it for a competing publication.</p>

    <h2>Advertising</h2>
    <p>If Google AdSense is later enabled, advertisements may appear on eligible public pages only. Ads are marked as advertisements. Do not treat an advertisement as a school notice, an admission button, or a request from the office. The school does not ask anyone to click advertisements.</p>

    <h2>Availability</h2>
    <p>We aim to keep the site available. Maintenance, hosting faults, or force majeure may interrupt it. The desks are not a substitute for coming to the office when a matter is urgent.</p>

    <h2>Liability</h2>
    <p>To the extent the law allows, the school is not liable for loss that arises from relying solely on a public web page instead of confirming with the office, or from an interruption of the website. Nothing here limits liability that cannot be limited under Nigerian law.</p>

    <h2>Contact</h2>
    <p>{{ \App\Support\SchoolIdentity::name() }}<br>
      {!! \App\Support\SchoolIdentity::addressHtml() !!}<br>
      <a href="tel:{{ \App\Support\SchoolIdentity::phone() }}">{{ \App\Support\SchoolIdentity::phone() }}</a> ·
      <a href="mailto:{{ \App\Support\SchoolIdentity::email() }}">{{ \App\Support\SchoolIdentity::email() }}</a></p>
  </article>
  </div>
@endsection

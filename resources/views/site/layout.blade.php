<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="/site/Image/logo_main.png">
  <link rel="apple-touch-icon" href="/site/Image/logo_main.png">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'News & Insights') | {{ \App\Support\SchoolIdentity::name() }}</title>
  <meta name="description" content="@yield('meta_description', 'Guidance for families from Supreme Reagan Schools — study, parenting, admissions, and the life of the house.')">
  <link rel="canonical" href="@yield('canonical', url()->current())">
  <meta name="robots" content="{{ !empty($indexable) ? 'index,follow' : 'noindex,nofollow' }}">
  <meta property="og:site_name" content="{{ \App\Support\SchoolIdentity::name() }}">
  <meta property="og:type" content="@yield('og_type', 'website')">
  <meta property="og:title" content="@yield('og_title', trim($__env->yieldContent('title').' | '.\App\Support\SchoolIdentity::name()))">
  <meta property="og:description" content="@yield('og_description', trim($__env->yieldContent('meta_description')))">
  <meta property="og:url" content="@yield('canonical', url()->current())">
  <meta property="og:image" content="@yield('og_image', url('/site/Image/logo_main.png'))">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@yield('og_title', trim($__env->yieldContent('title').' | '.\App\Support\SchoolIdentity::name()))">
  <meta name="twitter:description" content="@yield('og_description', trim($__env->yieldContent('meta_description')))">
  <meta name="twitter:image" content="@yield('og_image', url('/site/Image/logo_main.png'))">
  @php
    $ads = app(\App\Services\News\AdSenseService::class);
    $settings = $ads->settings();
    $client = $ads->clientId();
    $article = request()->attributes->get('article');
    $adsEligible = !empty($adsEligible) && $ads->mayRender(request(), $article instanceof \App\Models\Post ? $article : null);
  @endphp
  @if($client)
    <meta name="google-adsense-account" content="{{ $client }}">
  @endif
  @if(filled($settings->adsenseVerification()) && preg_match('/^[A-Za-z0-9_\-]{10,80}$/', (string) $settings->adsenseVerification()))
    <meta name="google-site-verification" content="{{ $settings->adsenseVerification() }}">
  @endif
  @if($adsEligible && $settings->adsenseAutoAds() && $client)
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $client }}" crossorigin="anonymous"></script>
  @endif
  @if($settings->analyticsReady() && $ads->hasAnalyticsConsent(request()))
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $settings->analyticsMeasurementId() }}"></script>
    <script id="srs-gtag" data-measurement-id="{{ $settings->analyticsMeasurementId() }}">
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', document.getElementById('srs-gtag').dataset.measurementId, { anonymize_ip: true });    </script>
  @endif
  <link rel="alternate" type="application/rss+xml" title="Supreme Reagan Schools News" href="{{ url('/feed') }}">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,500;0,600;0,700;1,500;1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/site/CSS/index.css?v=20260904b">
  <link rel="stylesheet" href="/site/CSS/news.css?v=20260830a">
  @stack('head')
</head>
<body class="inner-page news-site @yield('body_class')">
  <a href="https://wa.me/2349065641343" class="whatsapp-float" target="_blank" rel="noopener">
    <i class="bi bi-whatsapp"></i>
    <span>Chat us</span>
  </a>
  <header class="site-chrome">
    <div class="scroll-progress" aria-hidden="true"><i></i></div>
    <div class="top-info-bar" aria-label="Announcement">
      <div class="top-info-left">
        <span class="house-pulse" data-house-lamp>
          <i></i>
          <span data-house-state>The house is open</span>
        </span>
        <span data-house-clock class="house-clock">Lagos</span>
      </div>
      <div class="top-info-center">
        <div class="top-info-track">
          <span>Growth Begins Here. Knowledge · Character · Excellence. Enroll today! Call {{ \App\Support\SchoolIdentity::phone() }}</span>
        </div>
      </div>
      <div class="top-info-right">
        <a href="tel:{{ \App\Support\SchoolIdentity::phone() }}">{{ \App\Support\SchoolIdentity::phone() }}</a>
        <a href="/admissions#applicationForm">Apply</a>
      </div>
    </div>
    <nav id="mainNavbar" class="navbar house-bar">
      <div class="house-bar-inner">
        <a class="nav-logo" href="/">
          <img src="/site/Image/logo_main.png?v=20260821" alt="Supreme Reagan Schools">
          <span class="nav-wordmark">
            <strong>Supreme Reagan</strong>
            <small>Knowledge · Character · Excellence</small>
          </span>
        </a>
        <button class="menu-toggle" type="button" aria-label="Open menu" aria-controls="classicMenu" aria-expanded="false">
          <span></span><span></span><span></span>
        </button>
        <div class="house-nav">
          <span class="nav-ink" aria-hidden="true"></span>
          <a class="house-link{{ request()->is('/') ? ' is-current' : '' }}" data-nav="home" href="/">Home</a>
          <a class="house-link{{ request()->is('about') ? ' is-current' : '' }}" href="/about">About</a>
          <a class="house-link{{ request()->is('admissions') ? ' is-current' : '' }}" href="/admissions">Admissions</a>
          <div class="house-item">
            <button type="button" class="house-trigger{{ request()->is(['nursery', 'primary', 'secondary', 'branches', 'resources*']) ? ' is-current' : '' }}" aria-expanded="false" aria-controls="housePanel">The House</button>
            <div class="house-panel" id="housePanel">
              <a href="/nursery"><strong>Nursery</strong><span>First rooms of wonder</span></a>
              <a href="/primary"><strong>Primary</strong><span>Literacy, numeracy and character</span></a>
              <a href="/secondary"><strong>Secondary</strong><span>Rigour and a future-ready mind</span></a>
              <a href="/branches"><strong>Campus</strong><span>Amakohia-Akwakuma, Owerri</span></a>
              <a href="/resources"><strong>Resources</strong><span>Parent guidance and study notes</span></a>
            </div>
          </div>
          <a class="house-link{{ request()->is('news*') ? ' is-current' : '' }}" href="/news">News</a>
          <a class="house-link{{ request()->is('contact') ? ' is-current' : '' }}" href="/contact">Contact</a>
        </div>
        <div class="house-actions">
          <div class="portal-dock">
            <button type="button" class="portal-trigger" aria-expanded="false">Enter</button>
            <div class="portal-panel">
              <a href="/staff/login"><strong>Staff</strong><span>Faculty desk</span></a>
              <a href="/parent/login"><strong>Parent</strong><span>Household desk</span></a>
              <a href="/student/login"><strong>Student</strong><span>Pupil house</span></a>
              <a href="/alumni"><strong>Alumni</strong><span>After the last bell</span></a>
            </div>
          </div>
          <a class="house-apply" href="/admissions#applicationForm">Apply</a>
        </div>
      </div>
    </nav>
  </header>
  <div class="classic-menu" id="classicMenu">
    <div class="classic-menu-inner">
      <p class="classic-menu-kicker">Knowledge · Character · Excellence</p>
      <nav class="classic-menu-nav">
        <a href="/" @class(['is-current' => request()->is('/')])><span>01</span>Home</a>
        <a href="/about" @class(['is-current' => request()->is('about')])><span>02</span>About</a>
        <a href="/admissions" @class(['is-current' => request()->is('admissions')])><span>03</span>Admissions</a>
        <div @class(['classic-menu-wing', 'is-current' => request()->is(['nursery', 'primary', 'secondary', 'branches', 'resources*'])])>
          <button type="button" class="classic-menu-house-trigger" aria-expanded="false" aria-controls="classicHousePanel">
            <span>04</span>The House
          </button>
          <div class="classic-menu-panel" id="classicHousePanel">
            <a href="/nursery" @class(['is-current' => request()->is('nursery')])><strong>Nursery</strong><span>First rooms of wonder</span></a>
            <a href="/primary" @class(['is-current' => request()->is('primary')])><strong>Primary</strong><span>Literacy, numeracy and character</span></a>
            <a href="/secondary" @class(['is-current' => request()->is('secondary')])><strong>Secondary</strong><span>Rigour and a future-ready mind</span></a>
            <a href="/branches" @class(['is-current' => request()->is('branches')])><strong>Campus</strong><span>Amakohia-Akwakuma, Owerri</span></a>
            <a href="/resources" @class(['is-current' => request()->is('resources*')])><strong>Resources</strong><span>Parent guidance and study notes</span></a>
          </div>
        </div>
        <a href="/news" @class(['is-current' => request()->is('news*')])><span>05</span>News</a>
        <a href="/contact" @class(['is-current' => request()->is('contact')])><span>06</span>Contact</a>
      </nav>
      <div class="classic-menu-doors">
        <a href="/staff/login">Staff</a>
        <a href="/parent/login">Parent</a>
        <a href="/student/login">Student</a>
        <a href="/alumni">Alumni</a>
      </div>
      <div class="classic-menu-foot">
        <a href="tel:{{ \App\Support\SchoolIdentity::phone() }}">{{ \App\Support\SchoolIdentity::phone() }}</a>
        <a href="/admissions#applicationForm" class="btn nav-btn text-white fw-bolder rounded-pill px-4 py-2">Apply Now</a>
      </div>
    </div>
  </div>

  <main class="news-page">
    @yield('content')
  </main>

  <footer class="main-footer">
    <div class="container">
      <div class="footer-main">
        <div class="footer-about">
          <a href="/" class="footer-logo">
            <img src="/site/Image/logo_main.png?v=20260821" alt="Supreme Reagan Schools Logo">
          </a>
          <p>Visible academic results and personal growth, in a beautiful environment, through effective teaching and a collaborative community.</p>
        </div>
        <div class="footer-column">
          <h4>Quick Links</h4>
          <ul>
            <li><a href="/">Home</a></li>
            <li><a href="/about">About Us</a></li>
            <li><a href="/admissions">Admissions</a></li>
            <li><a href="/news">News &amp; Insights</a></li>
            <li><a href="/resources">Parent Resources</a></li>
            <li><a href="/nursery">Nursery</a></li>
            <li><a href="/primary">Primary</a></li>
            <li><a href="/secondary">Secondary</a></li>
          </ul>
        </div>
        <div class="footer-column">
          <h4>Useful Links</h4>
          <ul>
            <li><a href="/branches">Our Branches</a></li>
            <li><a href="/contact">Contact Us</a></li>
            <li><a href="/privacy">Privacy Policy</a></li>
            <li><a href="/terms">Terms</a></li>
            <li><a href="/alumni">Alumni</a></li>
            <li><a href="/staff/login">Staff Portal</a></li>
          </ul>
        </div>
        <div class="footer-column footer-contact">
          <h4>Get In Touch</h4>
          <div class="footer-contact-item">
            <i class="bi bi-geo-alt-fill"></i>
            <span>{!! \App\Support\SchoolIdentity::addressHtml() !!}</span>
          </div>
          <div class="footer-contact-item">
            <i class="bi bi-telephone-fill"></i>
            <a href="tel:{{ \App\Support\SchoolIdentity::phone() }}">{{ \App\Support\SchoolIdentity::phone() }}</a>
          </div>
          <div class="footer-contact-item">
            <i class="bi bi-envelope-fill"></i>
            <a href="mailto:{{ \App\Support\SchoolIdentity::email() }}">{{ \App\Support\SchoolIdentity::email() }}</a>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <p class="footer-copy">&copy; {{ date('Y') }} Supreme Reagan Schools. All Rights Reserved.</p>
        <p class="footer-motto"><span>Knowledge · Character · Excellence</span></p>
        <p class="footer-maker">
          <i aria-hidden="true">ET</i>
          <span>Developed by<strong>ERIBS Tech</strong></span>
        </p>
      </div>
    </div>
  </footer>

  @include('site.partials.cookie-consent')
  @include('site.partials.school-jsonld')
  @stack('jsonld')

  <script src="/site/JS/nav.js?v=20260829e"></script>
  <script src="/site/JS/portal-public.js"></script>
  <script src="/site/JS/site-consent.js"></script>
  <script src="/site/JS/site-analytics.js"></script>
  <script src="/site/JS/site-enquiry.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  @stack('scripts')
</body>
</html>

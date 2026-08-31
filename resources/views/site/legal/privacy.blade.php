@extends('site.layout')

@section('title', 'Privacy Policy')
@section('meta_description', 'How Supreme Reagan Schools collects, uses, and protects information on the public website and school desks.')
@section('canonical', url('/privacy'))
@section('body_class', 'legal-page')

@section('content')
  @include('site.partials.news-hero', [
    'title' => 'Privacy Policy',
    'crumbs' => [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => 'Privacy Policy'],
    ],
  ])

  <div class="container-xxl py-5 news-journal">
  <article class="legal-doc">
    <p class="legal-updated">Last reviewed: 29 August 2026. This page describes current practice. It is not a warranty and does not create rights beyond applicable law.</p>

    <h2>Who we are</h2>
    <p>{{ \App\Support\SchoolIdentity::name() }} is a school in Owerri, Imo State. The public website, News &amp; Insights, and the staff, parent, and pupil desks are operated for the school’s educational work.</p>
    <p>{!! \App\Support\SchoolIdentity::addressHtml() !!}<br>
      Telephone: <a href="tel:{{ \App\Support\SchoolIdentity::phone() }}">{{ \App\Support\SchoolIdentity::phone() }}</a><br>
      Email: <a href="mailto:{{ \App\Support\SchoolIdentity::email() }}">{{ \App\Support\SchoolIdentity::email() }}</a></p>

    <h2>Information we collect</h2>
    <p>Depending on how you use the site, we may process:</p>
    <ul>
      <li>Contact and admission form details that you submit (name, telephone, email, and the message or application you write).</li>
      <li>Account details for staff, parents, and pupils who are given access to a school desk.</li>
      <li>School records that belong to the house: enrolment, attendance, fees, and classroom work, held for the child’s education.</li>
      <li>Technical data such as IP address, browser type, and pages requested, which web servers typically record.</li>
    </ul>
    <p>We do not ask the public website for payment-card numbers. We do not sell personal information.</p>

    <h2>Contact forms</h2>
    <p>Messages sent through the contact or admissions forms are read by the school office so we can reply. Keep them accurate. Do not send another person’s private data unless you are that person’s parent or guardian and the school needs it.</p>

    <h2>Newsletter</h2>
    <p>If you later give an email address and a clear yes for a public newsletter, the office will store that address and the time of consent. We will not subscribe a visitor automatically. You may ask the office to stop using the address.</p>

    <h2>Cookies</h2>
    <p>Necessary cookies keep a logged-in session and protect forms against forgery. They are required for the desks to work.</p>
    <p>Optional cookies are used only after you choose them:</p>
    <ul>
      <li>Analytics cookies, if the school later enables a measurement ID, help us understand which public pages are used. We do not send pupil names or admission numbers to analytics.</li>
      <li>Advertising cookies, if Google AdSense is later enabled with a real publisher ID, may be set by Google on eligible public pages only.</li>
    </ul>
    <p>You can choose “Necessary only” or allow optional cookies. The choice is stored on your device. It does not unlock the private desks.</p>

    <h2>Google services and advertising</h2>
    <p>If the school enables Google Analytics or Google AdSense, Google may process cookie identifiers and approximate location according to Google’s own policies. Personalized advertising is not shown inside staff, parent, or pupil desks, login screens, or fee pages. Pages written mainly for children can have advertisements turned off by the editor.</p>
    <p>Google’s advertising cookies and similar technologies are described in Google’s publisher documentation. Enabling those products does not mean the site is approved for AdSense; approval remains Google’s decision.</p>

    <h2>Third-party services</h2>
    <p>The public pages may load fonts or interface libraries from their publishers. WhatsApp links open WhatsApp’s service. Portal email uses the school’s configured mailbox. Each provider has its own terms.</p>

    <h2>How we use information</h2>
    <p>We use information to run the school, reply to families, keep the website working, and — only if you consent and the school has configured them — measure public traffic or show advertising on eligible public pages.</p>

    <h2>Retention</h2>
    <p>School records are kept for as long as the house needs them for education, regulation, or a lawful dispute, then removed or archived according to office practice. Public form messages are kept while they are useful for the enquiry. Server logs are rotated in the ordinary course of hosting.</p>

    <h2>Your rights</h2>
    <p>Parents and staff may ask the office what records we hold about them or their children, and ask for a correction where a record is wrong. You may withdraw optional cookie consent at any time by using the cookie choices again. Access to a school desk follows the account the house issued; it is not a public right of account creation.</p>

    <h2>Security</h2>
    <p>We use signed-in sessions, role checks, and ordinary hosting protections. No method of transmission or storage is perfectly secure. Do not share desk passwords. Report suspected misuse to the office email above.</p>

    <h2>Children</h2>
    <p>The public website is written for families and the school community. The pupil desk is a private record for enrolled children and is not a place for advertising. We do not knowingly use the public site to collect a child’s data for marketing.</p>

    <h2>Changes</h2>
    <p>If this policy changes in a material way, we will update this page. Continued use of the public website after a change means you should read the new text.</p>

    <h2>Contact</h2>
    <p>Questions about this policy: <a href="mailto:{{ \App\Support\SchoolIdentity::email() }}">{{ \App\Support\SchoolIdentity::email() }}</a> or the <a href="/contact">contact page</a>.</p>
  </article>
  </div>
@endsection

@php
  $school = [
    '@context' => 'https://schema.org',
    '@type' => 'EducationalOrganization',
    'name' => \App\Support\SchoolIdentity::name(),
    'url' => url('/'),
    'logo' => \App\Support\SchoolIdentity::logoUrl(),
    'email' => \App\Support\SchoolIdentity::email(),
    'telephone' => \App\Support\SchoolIdentity::phone(),
    'address' => [
      '@type' => 'PostalAddress',
      'streetAddress' => \App\Support\SchoolIdentity::addressText(),
      'addressCountry' => 'NG',
    ],
    'motto' => \App\Support\SchoolIdentity::motto(),
  ];
@endphp
<script type="application/ld+json">@json($school, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>

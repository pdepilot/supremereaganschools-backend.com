@php
  $heroImage = $heroImage ?? '/site/Image/class_pics1.jpg';
  $crumbs = $crumbs ?? [
      ['label' => 'Home', 'url' => url('/')],
      ['label' => $title],
  ];
@endphp
<div class="container-fluid page-header py-5 mb-5">
  <div class="page-header-media" aria-hidden="true">
    <img src="{{ $heroImage }}" alt="">
  </div>
  <div class="page-header-shade"></div>
  <div class="container text-center py-5 page-header-copy">
    <h1 class="display-3 text-white mb-4">{{ $title }}</h1>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb justify-content-center mb-0">
        @foreach($crumbs as $crumb)
          @if(! empty($crumb['url']) && ! $loop->last)
            <li class="breadcrumb-item"><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a></li>
          @else
            <li class="breadcrumb-item active" aria-current="page">{{ $crumb['label'] }}</li>
          @endif
        @endforeach
      </ol>
    </nav>
  </div>
</div>

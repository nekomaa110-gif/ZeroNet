@php
    $brand   = config('site.brand');
    $waLink  = 'https://wa.me/'.config('site.whatsapp').'?text='.rawurlencode(config('site.whatsapp_text'));
    $metaDsc = trim($__env->yieldContent('meta_description') ?: config('site.description'));
    $canon   = url()->current();
@endphp
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#161A18" media="(prefers-color-scheme: dark)">

  <title>@yield('title', $brand.' · Internet Cepat dan Mudah')</title>
  <meta name="description" content="{{ $metaDsc }}">
  <link rel="canonical" href="{{ $canon }}">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="{{ $brand }}">
  <meta property="og:title" content="@yield('title', $brand.' · Internet Cepat dan Mudah')">
  <meta property="og:description" content="{{ $metaDsc }}">
  <meta property="og:url" content="{{ $canon }}">
  <meta property="og:locale" content="id_ID">
  <meta name="twitter:card" content="summary">

  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
  <link rel="alternate icon" href="/favicon.ico">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="{{ asset('assets/zn-tokens.css') }}?v={{ @filemtime(public_path('assets/zn-tokens.css')) ?: 1 }}">
  <link rel="stylesheet" href="{{ asset('assets/site-chrome.css') }}?v={{ @filemtime(public_path('assets/site-chrome.css')) ?: 1 }}">
  <link rel="stylesheet" href="{{ asset('assets/site.css') }}?v={{ @filemtime(public_path('assets/site.css')) ?: 1 }}">
  @stack('head')
</head>
<body>
  <a href="#konten" class="skip-link">Lewati ke konten</a>

  @include('site.partials.header')

  <main id="konten">
    @yield('content')
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <div class="cols">
        <div>
          <div class="brand"><span class="mark">@include('site.partials.logo')</span><b>{{ $brand }}</b></div>
          <p>{{ config('site.description') }}</p>
        </div>

        <div>
          <h4>Layanan</h4>
          <a href="{{ route('site.packages') }}">Paket internet</a>
          <a href="{{ route('site.coverage') }}">Area layanan</a>
          @if (config('site.status_check_enabled'))
            <a href="{{ route('site.status') }}">Cek status</a>
          @endif
        </div>

        <div>
          <h4>Bantuan</h4>
          <a href="{{ route('site.help') }}">Pusat bantuan</a>
          <a href="{{ $waLink }}" rel="noopener" target="_blank">WhatsApp</a>
          <a href="{{ route('customer.login') }}">Portal pelanggan</a>
        </div>

        <div>
          <h4>Jam layanan</h4>
          <dl class="hours">
            @foreach (config('site.hours', []) as $day => $time)
              <dt>{{ $day }}</dt><dd>{{ $time }}</dd>
            @endforeach
          </dl>
        </div>
      </div>

      <div class="copy">&copy; {{ date('Y') }} {{ $brand }}</div>
    </div>
  </footer>

  @include('site.partials.wa-button')

  <script src="{{ asset('assets/site-header.js') }}?v={{ @filemtime(public_path('assets/site-header.js')) ?: 1 }}"></script>
</body>
</html>

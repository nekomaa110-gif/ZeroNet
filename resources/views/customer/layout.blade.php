<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#161A18" media="(prefers-color-scheme: dark)">
  <title>@yield('title', 'Pelanggan ZeroNet')</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="{{ asset('assets/zn-tokens.css') }}?v={{ @filemtime(public_path('assets/zn-tokens.css')) ?: 1 }}">
  <link rel="stylesheet" href="{{ asset('assets/site-chrome.css') }}?v={{ @filemtime(public_path('assets/site-chrome.css')) ?: 1 }}">
  <link rel="stylesheet" href="{{ asset('assets/portal.css') }}?v={{ @filemtime(public_path('assets/portal.css')) ?: 1 }}">
  @stack('head')
</head>
<body>
  <a href="#konten" class="skip-link">Lewati ke konten</a>
  <div class="shell">
    @hasSection('topbar')
      @yield('topbar')
    @else
      @include('site.partials.header')
    @endif

    <main class="content @yield('content-class')" id="konten">
      @if (session('success'))
        <div class="alert ok" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span>{{ session('success') }}</span></div>
      @endif
      @if (session('info'))
        <div class="alert info" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><span>{{ session('info') }}</span></div>
      @endif
      @if (session('error'))
        <div class="alert err" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg><span>{{ session('error') }}</span></div>
      @endif

      @yield('content')
    </main>

    <div class="foot">© {{ date('Y') }} {{ config('site.brand') }}</div>
  </div>
  @hasSection('fab')
    @yield('fab')
  @else
    @include('site.partials.wa-button')
  @endif
  <script src="{{ asset('assets/site-header.js') }}?v={{ @filemtime(public_path('assets/site-header.js')) ?: 1 }}"></script>
</body>
</html>

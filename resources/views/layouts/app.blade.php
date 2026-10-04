<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <meta name="verify-password-url" content="{{ route('password.verify') }}">
  <meta name="robots" content="noindex, nofollow">
  <title>@yield('title', 'Dashboard')</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <script src="{{ asset('assets/shell.js') }}?v={{ @filemtime(public_path('assets/shell.js')) ?: 1 }}"></script>

  @vite(['resources/css/app.css', 'resources/js/app.js'])

  @foreach (['zn-tokens', 'app', 'panel-shell', 'pages/patterns', 'pages/dashboard', 'pages/billing', 'pages/network', 'pages/whatsapp', 'pages/voucher', 'pages/customers', 'pages/accounts', 'pages/logs', 'ui-utils'] as $css)
    <link rel="stylesheet" href="{{ asset('assets/'.$css.'.css') }}?v={{ @filemtime(public_path('assets/'.$css.'.css')) ?: 1 }}" />
  @endforeach
  <link rel="stylesheet" href="{{ asset('assets/page-transition.css') }}?v={{ @filemtime(public_path('assets/page-transition.css')) ?: 1 }}" />
  <script src="{{ asset('assets/page-transition.js') }}?v={{ @filemtime(public_path('assets/page-transition.js')) ?: 1 }}"></script>

  @stack('head')
</head>
<body class="@yield('page-class')">
  <a href="#konten" class="skip-link">Lewati ke konten</a>
  <div class="shell">
    @include('partials.sidebar', ['active' => $active ?? ''])
    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <div class="main">
      @include('partials.topbar', ['title' => $title ?? View::yieldContent('page-title') ?: 'Dashboard'])

      <main class="page" id="konten" data-screen-label="@yield('title')">
        @php
          $waDown = auth()->user()?->isAdmin()
            ? \Illuminate\Support\Facades\Cache::get(\App\Console\Commands\WaGatewayWatchdog::DOWN_KEY)
            : null;
        @endphp
        @if ($waDown && time() - ($waDown['since'] ?? time()) >= \App\Console\Commands\WaGatewayWatchdog::ALERT_AFTER_SECONDS)
          <div style="margin-bottom:16px">
            <x-admin.alert type="error" message="Gateway WhatsApp terputus (status {{ $waDown['status'] ?? '-' }}) sejak {{ date('d M Y H:i', $waDown['since']) }}. Pesan ke pelanggan dan admin tidak terkirim. Sambungkan ulang di menu WhatsApp, bagian Gateway."/>
          </div>
        @endif
        @yield('content')
      </main>
    </div>
  </div>

  @php
    $flashes = array_filter([
      'ok'   => session('success'),
      'err'  => session('error'),
      'warn' => session('forbidden'),
    ]);
  @endphp
  @if ($flashes)
    <div class="flash-stack" role="status" aria-live="polite">
      @foreach ($flashes as $tone => $message)
        <div class="flash-toast {{ $tone }}" data-flash title="Klik untuk menutup"><span>{{ $message }}</span></div>
      @endforeach
    </div>
  @endif

  <script src="{{ asset('assets/tweaks.js') }}?v={{ @filemtime(public_path('assets/tweaks.js')) ?: 1 }}"></script>
  <script>window.mountTweaks && window.mountTweaks();</script>

  <script src="{{ asset('assets/confirm-dialog.js') }}?v={{ @filemtime(public_path('assets/confirm-dialog.js')) ?: 1 }}"></script>
  <script src="{{ asset('assets/modal-lock.js') }}?v={{ @filemtime(public_path('assets/modal-lock.js')) ?: 1 }}"></script>

  <script src="{{ asset('assets/panel-live.js') }}?v={{ @filemtime(public_path('assets/panel-live.js')) ?: 1 }}"></script>

  @stack('scripts')
  @stack('overlays')
</body>
</html>

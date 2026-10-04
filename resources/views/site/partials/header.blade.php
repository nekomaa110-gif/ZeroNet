@php
    $brand = config('site.brand');
    $loggedIn = auth('customer')->check();
@endphp

<header class="site-header">
  <div class="wrap">
    <a href="{{ route('home') }}" class="brand" aria-label="{{ $brand }}, beranda">
      <span class="mark">@include('site.partials.logo')</span>
      <b>{{ $brand }}</b>
    </a>

    <nav class="nav" aria-label="Menu utama">
      <a href="{{ route('home') }}" data-spy="beranda" @if(request()->routeIs('home')) aria-current="page" @endif>Beranda</a>
      <a href="{{ route('site.packages') }}" data-spy="paket" @if(request()->routeIs('site.packages')) aria-current="page" @endif>Paket</a>
      <a href="{{ route('site.coverage') }}" data-spy="area" @if(request()->routeIs('site.coverage')) aria-current="page" @endif>Area Layanan</a>
      <a href="{{ route('site.help') }}" @if(request()->routeIs('site.help')) aria-current="page" @endif>Bantuan</a>
      @if (config('site.status_check_enabled'))
        <a href="{{ route('site.status') }}" @if(request()->routeIs('site.status', 'site.status.check')) aria-current="page" @endif>Cek Status</a>
      @endif

      @if ($loggedIn)
        <a href="{{ route('customer.dashboard') }}" @if(request()->routeIs('customer.dashboard')) aria-current="page" @endif>Akun Saya</a>
        <form method="POST" action="{{ route('customer.logout') }}" class="nav-logout">@csrf
          <button type="submit">Keluar</button>
        </form>
      @else
        <a href="{{ route('customer.login') }}" class="nav-cta" @if(request()->routeIs('customer.login')) aria-current="page" @endif>Portal Pelanggan</a>
      @endif
    </nav>

    <button type="button" class="nav-toggle" aria-label="Buka menu" aria-expanded="false" aria-controls="menu-mobile" data-nav-toggle>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M4 7h16M4 12h16M4 17h16"/>
      </svg>
    </button>

    <div class="mobile-nav" id="menu-mobile" hidden>
      <a href="{{ route('home') }}" data-spy="beranda" @if(request()->routeIs('home')) aria-current="page" @endif>Beranda</a>
      <a href="{{ route('site.packages') }}" data-spy="paket" @if(request()->routeIs('site.packages')) aria-current="page" @endif>Paket</a>
      <a href="{{ route('site.coverage') }}" data-spy="area" @if(request()->routeIs('site.coverage')) aria-current="page" @endif>Area Layanan</a>
      <a href="{{ route('site.help') }}" @if(request()->routeIs('site.help')) aria-current="page" @endif>Bantuan</a>
      @if (config('site.status_check_enabled'))
        <a href="{{ route('site.status') }}" @if(request()->routeIs('site.status', 'site.status.check')) aria-current="page" @endif>Cek Status</a>
      @endif

      @if ($loggedIn)
        <a href="{{ route('customer.dashboard') }}" @if(request()->routeIs('customer.dashboard')) aria-current="page" @endif>Akun Saya</a>
        <form method="POST" action="{{ route('customer.logout') }}" class="mobile-logout">@csrf
          <button type="submit">Keluar</button>
        </form>
      @else
        <a href="{{ route('customer.login') }}" @if(request()->routeIs('customer.login')) aria-current="page" @endif>Portal Pelanggan</a>
      @endif
    </div>
  </div>
</header>

@php
    $crumbs = \App\Support\PanelMenu::crumb();
    $pageTitle = trim(html_entity_decode((string) ($title ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $last = end($crumbs);
    if ($pageTitle !== '' && (! $last || $last['label'] !== $pageTitle)) {
        $crumbs[] = ['label' => $pageTitle, 'url' => null];
    }
@endphp
<header class="topbar">
  <button type="button" class="icon-btn sidebar-toggle-btn" data-sidebar-toggle aria-label="Buka menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
  </button>

  <nav class="crumb" aria-label="Lokasi halaman">
    @foreach ($crumbs as $c)
      @if (! $loop->last)
        @if ($c['url'])<a href="{{ $c['url'] }}">{{ $c['label'] }}</a>@else<span>{{ $c['label'] }}</span>@endif
        <span class="sep" aria-hidden="true">/</span>
      @else
        <b aria-current="page">{{ $c['label'] }}</b>
      @endif
    @endforeach
  </nav>

  <div class="topbar-spacer"></div>

  <div class="netstat" data-netstat data-url="{{ route('dashboard.live') }}">
    <a href="{{ route('routers.index') }}" title="Status router">
      <span class="led" data-ns-led aria-hidden="true"></span>
      Router <b data-ns-routers>...</b>
    </a>
    <span class="sepv ns-online" aria-hidden="true"></span>
    <span class="ns-online" title="Sesi hotspot online">Online <b data-ns-online>...</b></span>
  </div>
</header>

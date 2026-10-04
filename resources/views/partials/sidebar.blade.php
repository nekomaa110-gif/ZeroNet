@php
    $u = auth()->user();
    $isAdmin = $u && method_exists($u, 'isAdmin') ? $u->isAdmin() : ($u?->role === 'admin');
    $menu = \App\Support\PanelMenu::items();
    $displayName = $u->name ?? $u->username ?? 'guest';
@endphp

<aside class="sidebar" aria-label="Navigasi utama">
  <a class="sidebar-brand" href="{{ route('dashboard') }}">
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 200 200" fill="none"><polygon points="10,55 100,8 190,55 190,145 100,192 10,145" fill="none" stroke="currentColor" stroke-width="10" stroke-linejoin="round"/><rect x="48" y="60" width="18" height="80" rx="3" fill="currentColor"/><rect x="134" y="60" width="18" height="80" rx="3" fill="currentColor"/><path d="M 66,62 L 134,138 L 134,118 L 66,82 Z" fill="currentColor"/></svg>
    </span>
    <span class="brand-text"><b>ZeroNet</b><span>Panel Hotspot</span></span>
  </a>

  <nav class="nav">
    @foreach ($menu as $item)
      @if (! isset($item['children']))
        <a class="nav-item {{ $item['active'] ? 'active' : '' }}" href="{{ route($item['route']) }}" title="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif>
          {!! $item['icon'] !!}
          <span class="nav-label">{{ $item['label'] }}</span>
          @if (($item['count'] ?? 0) > 0)
            <span class="nav-count">{{ $item['count'] }}</span>
          @endif
        </a>
      @else
        <div class="nav-group {{ $item['active'] ? 'open has-active' : '' }}" data-nav-group>
          <button type="button" class="nav-item nav-group-btn" data-nav-group-btn
                  aria-expanded="{{ $item['active'] ? 'true' : 'false' }}" aria-controls="nav-sub-{{ $loop->index }}" title="{{ $item['label'] }}">
            {!! $item['icon'] !!}
            <span class="nav-label">{{ $item['label'] }}</span>
            @if ($item['count'] > 0)
              <span class="nav-count" aria-label="{{ $item['count'] }} menunggu">{{ $item['count'] }}</span>
            @endif
            <svg class="nav-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
          </button>
          <div class="nav-sub" id="nav-sub-{{ $loop->index }}">
            <div class="nav-sub-inner">
              <div class="nav-sub-title">{{ $item['label'] }}</div>
              @foreach ($item['children'] as $child)
                <a class="nav-subitem {{ $child['active'] ? 'active' : '' }}" href="{{ route($child['route']) }}" @if($child['active']) aria-current="page" @endif>
                  <span>{{ $child['label'] }}</span>
                  @if (($child['count'] ?? 0) > 0)
                    <span class="nav-count">{{ $child['count'] }}</span>
                  @endif
                </a>
              @endforeach
            </div>
          </div>
        </div>
      @endif
    @endforeach
  </nav>

  <div class="sidebar-user">
    <div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}</div>
    <div class="user-meta">
      <b>{{ $displayName }}</b>
      <span>{{ ucfirst($u->role ?? 'admin') }}</span>
    </div>

    <div class="user-menu-wrap">
      <button class="icon-btn" data-user-menu type="button" aria-label="Menu akun" aria-haspopup="menu" aria-expanded="false" title="Menu akun">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/></svg>
      </button>

      <div class="user-pop" role="menu">
        <a href="{{ route('profile.edit') }}" class="user-pop-item" role="menuitem">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profil saya
        </a>
        <a href="{{ route('two-factor.index') }}" class="user-pop-item" role="menuitem">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Verifikasi 2 langkah
        </a>
        <button type="button" class="user-pop-item" role="menuitem" data-tweaks-open onclick="window.toggleTweaks && window.toggleTweaks()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/></svg>
          Tampilan
        </button>
        @if ($isAdmin)
          <a href="{{ route('operators.index') }}" class="user-pop-item" role="menuitem">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Operator
          </a>
        @endif
        <div class="user-pop-sep"></div>
        <form method="POST" action="{{ route('logout') }}">@csrf
          <button type="submit" class="user-pop-item danger" role="menuitem">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Keluar
          </button>
        </form>
      </div>
    </div>
  </div>
</aside>

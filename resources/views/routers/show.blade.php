@extends('layouts.app')

@section('title', $routerName)
@section('page-title', $routerName)

@section('page-class', 'pg-routers-show')

@section('content')

  <header class="page-head" x-data="routerStats('{{ route('routers.stats', $routerId) }}')" x-init="load()">
    <div>
      <div style="display:flex;align-items:center;gap:8px;color:var(--text-3);font-size:12px;margin-bottom:6px">
        <a class="muted" href="{{ route('routers.index') }}">Manajemen Router</a>
        <span>/</span>
        <span>{{ $routerName }}</span>
      </div>
      <h2>
        {{ $routerName }}
        <template x-if="loading"><span class="badge" style="font-size:12px;vertical-align:middle;margin-left:8px">Mengecek…</span></template>
        <template x-if="!loading && online"><span class="badge ok" style="font-size:12px;vertical-align:middle;margin-left:8px">Online</span></template>
        <template x-if="!loading && !online"><span class="badge err" style="font-size:12px;vertical-align:middle;margin-left:8px">Offline</span></template>
      </h2>
      <p class="mono">{{ $routerHost }} · {{ $routerInterface }} (WAN) <span x-show="!loading && online && stats.identity">· <span x-text="stats.identity"></span></span></p>
    </div>
    <div class="head-actions">
      <a class="btn" href="{{ route('routers.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Kembali
      </a>
      @if((auth()->user()->role ?? null) === 'admin')
        <form class="inline-form" method="POST" action="{{ route('routers.reboot', $routerId) }}"
              data-confirm="Yakin reboot router {{ $routerName }}? Semua koneksi aktif pelanggan akan terputus sementara (~30-60 detik)."
              data-confirm-title="Reboot Router"
              data-confirm-action="Reboot Sekarang"
              data-confirm-variant="warning"
             >
          @csrf
          <button type="submit" class="btn btn-warn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Reboot
          </button>
        </form>
        <a href="{{ route('routers.backup', $routerId) }}" class="btn btn-primary" data-page-transition="none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Backup Config
        </a>
      @endif
    </div>
  </header>

  <section x-data="routerStats('{{ route('routers.stats', $routerId) }}')" x-init="load()" x-cloak
           style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:22px">

    <template x-if="loading">
      <template x-for="i in 4" :key="i">
        <div class="res-card"><div class="skeleton" style="height:80px"></div></div>
      </template>
    </template>

    <template x-if="!loading && !online">
      <div class="card card-pad" style="grid-column:1/-1;border-color: color-mix(in srgb, var(--err) 30%, transparent); background: color-mix(in srgb, var(--err) 6%, var(--bg-elev));display:flex;align-items:center;gap:10px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--err);flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div class="grow">
          <div style="font-weight:600;color:var(--err)">Router tidak dapat dijangkau</div>
          <div style="font-size:12px;color:var(--text-3);margin-top:2px" x-text="error"></div>
        </div>
        <button @click="load()" class="btn btn-sm">Coba lagi</button>
      </div>
    </template>

    <template x-if="!loading && online">
      <div style="display:contents">
        <div class="res-card">
          <div class="rc-l">CPU Load</div>
          <div class="rc-v mono" :style="stats.cpu_load > 80 ? 'color:var(--err)' : stats.cpu_load > 50 ? 'color:var(--warn)' : 'color:var(--ok)'" x-text="stats.cpu_load + '%'"></div>
          <div class="progress" :class="stats.cpu_load > 80 ? 'err' : stats.cpu_load > 50 ? 'warn' : 'ok'"><i :style="'width:' + stats.cpu_load + '%'"></i></div>
          <div class="rc-s" x-text="(stats.cpu_count || '') + (stats.cpu_count ? ' cores · ' : '') + (stats.cpu || '')"></div>
        </div>

        <div class="res-card">
          <div class="rc-l">RAM</div>
          <div class="rc-v mono" :style="stats.mem_pct > 85 ? 'color:var(--err)' : stats.mem_pct > 60 ? 'color:var(--warn)' : 'color:var(--info)'" x-text="stats.mem_pct + '%'"></div>
          <div class="progress" :class="stats.mem_pct > 85 ? 'err' : stats.mem_pct > 60 ? 'warn' : ''"><i :style="'width:' + stats.mem_pct + '%'"></i></div>
          <div class="rc-s mono" x-text="fmtBytes(stats.used_mem) + ' / ' + fmtBytes(stats.total_mem)"></div>
        </div>

        <div class="res-card">
          <div class="rc-l">Storage</div>
          <div class="rc-v mono" :style="stats.hdd_pct > 85 ? 'color:var(--err)' : stats.hdd_pct > 60 ? 'color:var(--warn)' : 'color:var(--brand-3)'" x-text="stats.hdd_pct + '%'"></div>
          <div class="progress" :class="stats.hdd_pct > 85 ? 'err' : stats.hdd_pct > 60 ? 'warn' : ''"><i :style="'width:' + stats.hdd_pct + '%'"></i></div>
          <div class="rc-s mono" x-text="fmtBytes(stats.used_hdd) + ' / ' + fmtBytes(stats.total_hdd)"></div>
        </div>

        <div class="res-card">
          <div class="rc-l">Uptime</div>
          <div class="rc-v" style="font-size:22px" x-text="fmtUptime(stats.uptime)"></div>
          <div style="height:6px"></div>
          <div class="rc-s" x-text="(stats.identity || '') + (stats.version ? ' · ROS ' + stats.version : '')"></div>
        </div>
      </div>
    </template>
  </section>

  <div class="card card-pad rs-hotspot">
    <div>
      <h3>User hotspot aktif</h3>
      <p class="t-mute">Daftar sesi, perangkat, dan cookie login router ini sekarang ada di halaman Hotspot Aktif, lengkap dengan aksi putus sesi.</p>
    </div>
    <a href="{{ route('hotspot.index', ['router' => $routerId]) }}" class="btn">Buka Hotspot Aktif</a>
  </div>


@endsection

@push('scripts')
<script>
function routerStats(statsUrl) {
  return {
    statsUrl, loading: true, online: false, stats: {}, error: '',
    async load() {
      this.loading = true;
      try {
        const res = await fetch(this.statsUrl);
        const data = await res.json();
        this.online = data.online;
        this.stats  = data.stats ?? {};
        this.error  = data.error ?? '';
      } catch (e) { this.online = false; this.error = e.message; }
      finally { this.loading = false; }
    },
    fmtBytes(n) { n = parseInt(n) || 0; if (n >= 1073741824) return (n/1073741824).toFixed(1)+' GB'; if (n >= 1048576) return (n/1048576).toFixed(1)+' MB'; if (n >= 1024) return (n/1024).toFixed(1)+' KB'; return n+' B'; },
    fmtUptime(str) {
      if (!str) return '-';
      const label = { w:'mg', d:'hr', h:'j', m:'m' }; const parts = [];
      for (const [, num, unit] of str.matchAll(/(\d+)([wdhms])/g)) if (label[unit]) parts.push(num + label[unit]);
      return parts.join(' ') || '-';
    },
  };
}
</script>
@endpush

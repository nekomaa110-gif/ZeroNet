@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

  <header class="page-head">
    <div>
      <h2>Kondisi jaringan</h2>
      <p><span class="mono" id="dash-now">{{ now()->locale('id')->translatedFormat('l, d F Y H.i') }}</span></p>
    </div>
    <div class="head-actions">
      <a href="{{ route('user-hotspot.create') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah user
      </a>
    </div>
  </header>

  <section class="readout dash-readout" aria-label="Ringkasan user hotspot"
           x-data="{ s: @js($stats), init() { window.dashLive.on(d => { if (d.stats) this.s = d.stats; }); } }">
    <a class="rd-cell" href="{{ route('user-hotspot.index') }}">
      <div class="rd-label">Terdaftar</div>
      <div class="rd-value" x-text="s.total_users.toLocaleString('id-ID')">{{ number_format($stats['total_users'], 0, ',', '.') }}</div>
    </a>
    <a class="rd-cell" href="{{ route('user-hotspot.index', ['status' => 'aktif']) }}">
      <div class="rd-label">Aktif</div>
      <div class="rd-value" x-text="s.active_users.toLocaleString('id-ID')">{{ number_format($stats['active_users'], 0, ',', '.') }}</div>
    </a>
    <div>
      <div class="rd-label">Online sekarang</div>
      <div class="rd-value tnum" x-text="s.online_sessions.toLocaleString('id-ID')">{{ number_format($stats['online_sessions'], 0, ',', '.') }}</div>
    </div>
    <a class="rd-cell" href="{{ route('user-hotspot.index', ['status' => 'expired']) }}">
      <div class="rd-label">Expired</div>
      <div class="rd-value" :class="s.expired_users > 0 ? 'is-err' : ''" x-text="s.expired_users.toLocaleString('id-ID')">{{ number_format($stats['expired_users'], 0, ',', '.') }}</div>
    </a>
    <a class="rd-cell" href="{{ route('user-hotspot.index', ['status' => 'nonaktif']) }}">
      <div class="rd-label">Nonaktif</div>
      <div class="rd-value" :class="(s.disabled_users || 0) > 0 ? 'is-warn' : ''" x-text="(s.disabled_users || 0).toLocaleString('id-ID')">{{ number_format($stats['disabled_users'] ?? 0, 0, ',', '.') }}</div>
    </a>
  </section>

  <div class="section-bar">
    <h3>Router</h3>
    <span class="hint">{{ $routers->count() }} lokasi, diperbarui tiap 3 detik</span>
    <a href="{{ route('throughput.index') }}" class="btn btn-sm btn-ghost push-end">Riwayat throughput</a>
  </div>

  <section class="rtr-grid">
    @foreach ($routers as $router)
      <article class="rtr" x-data="routerUnit(@js($router['id']), @js($router['wans']))" :class="online === false ? 'is-down' : ''">
        <header class="rtr-head">
          <span class="led" :class="online === null ? '' : (online ? 'ok pulse' : 'err')" aria-hidden="true"></span>
          <div class="rtr-id">
            <h4>{{ $router['name'] }}</h4>
            <p class="mono">{{ $router['host'] ?? '' }} <span aria-hidden="true">/</span> <span x-text="wans.join(' + ')">{{ implode(' + ', $router['wans']) }}</span></p>
          </div>
          <div class="rtr-state">
            <span class="sr-only" x-text="online === null ? 'Menghubungkan' : (online ? 'Terhubung' : 'Tidak terhubung')"></span>
            <span class="mono t-mute" x-show="online && uptime" x-text="'up ' + uptime"></span>
            <span class="badge err" x-show="online === false" x-cloak>Offline</span>
            <a href="{{ route('routers.show', $router['id']) }}" class="btn btn-sm btn-ghost">Detail</a>
          </div>
        </header>

        <div class="rtr-rates">
          <div class="rate">
            <span class="swatch s1" aria-hidden="true"></span>
            <span class="rate-l">Download</span>
            <b class="tnum" x-text="dl">-</b>
          </div>
          <div class="rate">
            <span class="swatch s2" aria-hidden="true"></span>
            <span class="rate-l">Upload</span>
            <b class="tnum" x-text="ul">-</b>
          </div>
        </div>

        <div class="rtr-wans" x-show="perWan.length > 1" x-cloak>
          <template x-for="w in perWan" :key="w.interface">
            <div class="rtr-wan">
              <b class="mono" x-text="w.interface"></b>
              <span class="mono tnum" x-text="'dl ' + fmt(w.download ?? 0)"></span>
              <span class="mono tnum" x-text="'ul ' + fmt(w.upload ?? 0)"></span>
              <span class="badge err no-dot" x-show="w.exists && !w.running">link mati</span>
            </div>
          </template>
        </div>

        <div class="rtr-chart">
          <canvas x-ref="canvas" role="img" aria-label="Grafik trafik {{ $router['name'] }} 90 detik terakhir, download dan upload"></canvas>
        </div>

        <footer class="rtr-foot">
          <div class="meter-row">
            <span>CPU</span>
            <div class="progress" :class="cpu > 80 ? 'err' : cpu > 50 ? 'warn' : 'ok'"><i :style="'width:' + cpu + '%'"></i></div>
            <b class="mono tnum" x-text="online ? cpu + '%' : '-'">-</b>
          </div>
          <div class="meter-row">
            <span>RAM</span>
            <div class="progress" :class="ram > 80 ? 'err' : ram > 60 ? 'warn' : 'ok'"><i :style="'width:' + ram + '%'"></i></div>
            <b class="mono tnum" x-text="online ? ram + '%' : '-'">-</b>
          </div>
        </footer>
      </article>
    @endforeach
  </section>

  @if ($penjualan)
    @php
      $rp  = fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.');
      $hi  = $penjualan['hari_ini']['total'];
      $bi  = $penjualan['bulan_ini']['total'];
    @endphp

    <div class="section-bar">
      <h3>Penjualan voucher</h3>
      <a href="{{ route('penjualan.index') }}" class="btn btn-sm btn-ghost push-end">Laporan lengkap</a>
    </div>

    <section class="readout dash-penjualan" aria-label="Ringkasan penjualan voucher">
      <a class="rd-cell" href="{{ route('penjualan.index', ['dari' => now()->format('Y-m-d'), 'sampai' => now()->format('Y-m-d')]) }}">
        <div class="rd-label">Hari ini</div>
        <div class="rd-value tnum">{{ $rp($hi['omzet']) }}</div>
        <div class="rd-foot">{{ number_format($hi['transaksi'], 0, ',', '.') }} voucher terjual</div>
      </a>
      <a class="rd-cell" href="{{ route('penjualan.index', ['dari' => now()->startOfMonth()->format('Y-m-d'), 'sampai' => now()->format('Y-m-d')]) }}">
        <div class="rd-label">Bulan ini</div>
        <div class="rd-value tnum">{{ $rp($bi['omzet']) }}</div>
        <div class="rd-foot">{{ number_format($bi['transaksi'], 0, ',', '.') }} voucher terjual</div>
      </a>
      <div>
        <div class="rd-label">Harga jual bulan ini</div>
        @if ($bi['transaksi_harga_jual'] === 0)
          <div class="rd-value dash-kosong">belum diisi</div>
          <div class="rd-foot">isi harga jual di profil</div>
        @else
          <div class="rd-value tnum">{{ $rp($bi['harga_jual']) }}</div>
          <div class="rd-foot">{{ number_format($bi['transaksi_harga_jual'], 0, ',', '.') }} dari {{ number_format($bi['transaksi'], 0, ',', '.') }} transaksi</div>
        @endif
      </div>
      <div>
        <div class="rd-label">Margin bulan ini</div>
        <div class="rd-value tnum {{ $bi['margin'] < 0 ? 'is-err' : '' }}">{{ $bi['transaksi_harga_jual'] === 0 ? '-' : $rp($bi['margin']) }}</div>
        <div class="rd-foot">harga jual dikurangi harga</div>
      </div>
    </section>

    <div class="card dash-voucher">
      <div class="tbl-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Router</th>
              <th class="ta-r">Hari ini</th>
              <th class="ta-r">Bulan ini</th>
              <th class="ta-r">Belum dipakai</th>
              <th class="ta-r">Habis</th>
              <th>Terakhir ditarik</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($routerPenjualan as $r)
              @php
                $h = $penjualan['hari_ini']['per_router'][$r->id] ?? null;
                $b = $penjualan['bulan_ini']['per_router'][$r->id] ?? null;
                $v = $penjualan['voucher'][$r->id] ?? [];
                $segar = $r->last_sales_pull_at && $r->last_sales_pull_at->gt(now()->subMinutes(30));
              @endphp
              <tr>
                <td class="t-strong">{{ $r->name }}</td>
                <td class="mono ta-r">{{ $rp($h['omzet'] ?? 0) }} <span class="t-mute">· {{ $h['transaksi'] ?? 0 }}</span></td>
                <td class="mono ta-r">{{ $rp($b['omzet'] ?? 0) }} <span class="t-mute">· {{ number_format($b['transaksi'] ?? 0, 0, ',', '.') }}</span></td>
                <td class="mono ta-r">{{ number_format($v['ready'] ?? 0, 0, ',', '.') }}</td>
                <td class="mono ta-r">{{ number_format($v['expired'] ?? 0, 0, ',', '.') }}</td>
                <td>
                  @if ($segar)
                    <span class="t-mute">{{ $r->last_sales_pull_at->locale('id')->diffForHumans() }}</span>
                  @else
                    <span class="badge warn">{{ $r->last_sales_pull_at ? $r->last_sales_pull_at->locale('id')->diffForHumans() : 'belum ditarik' }}</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if ($penjualan['waktu_ragu'] > 0)
        <div class="tbl-foot">{{ number_format($penjualan['waktu_ragu'], 0, ',', '.') }} transaksi berwaktu tidak jelas tidak dihitung.</div>
      @endif
    </div>
  @endif

  <section class="card dash-log">
    <div class="card-head">
      <h3>Aktivitas terbaru</h3>
      @if (\Illuminate\Support\Facades\Route::has('activity-logs.index'))
        <div class="ch-actions">
          <a href="{{ route('activity-logs.index') }}" class="btn btn-sm btn-ghost">Semua log</a>
        </div>
      @endif
    </div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Oleh</th>
            <th>Aksi</th>
            <th class="hide-sm">Keterangan</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($recentActivities as $log)
            @php
              $isSystem = is_null($log->user_id);
              $userName = $isSystem ? 'Sistem' : ($log->user?->name ?? $log->user?->username ?? '');
            @endphp
            <tr>
              <td class="mono t-mute nowrap" title="{{ $log->created_at->format('d M Y H:i:s') }}">{{ str_replace(' yang lalu', ' lalu', $log->created_at->locale('id')->diffForHumans()) }}</td>
              <td class="{{ $isSystem ? 't-mute' : 't-strong' }} nowrap">{{ $userName }}</td>
              <td><span class="badge {{ \App\Support\ActivityAction::tone($log->action) }}">{{ \App\Support\ActivityAction::label($log->action) }}</span></td>
              <td class="hide-sm muted log-desc">{{ $log->description }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="empty-row">Belum ada aktivitas tercatat.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

@endsection

@push('scripts')
<script src="{{ asset('assets/chart.umd.min.js') }}"></script>
<script src="{{ asset('assets/zn-chart.js') }}?v={{ @filemtime(public_path('assets/zn-chart.js')) ?: 1 }}"></script>
<script>
  (function () {
    var el = document.getElementById('dash-now');
    var fmt = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    function tick() { if (el) el.textContent = fmt.format(new Date()); }
    tick(); setInterval(tick, 15000);
  })();

  window.dashLive = {
    url: @js(route('dashboard.live')),
    subs: [], last: null, timer: null, busy: false,
    on(fn) {
      this.subs.push(fn);
      if (this.last) fn(this.last);
      this.start();
    },
    kirim(d) {
      this.last = d;
      this.subs.forEach(fn => { try { fn(d); } catch (e) {} });
    },
    async poll() {
      if (this.busy) return;
      this.busy = true;
      const ctl = new AbortController();
      const to  = setTimeout(() => ctl.abort(), 4000);
      try {
        const r = await fetch(this.url, { signal: ctl.signal, headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        this.kirim(await r.json());
      } catch (e) { this.kirim({ gagal: true }); }
      finally { clearTimeout(to); this.busy = false; }
    },
    start() {
      if (this.timer !== null || document.hidden) return;
      this.timer = setInterval(() => this.poll(), 3000);
      this.poll();
    },
    stop() { clearInterval(this.timer); this.timer = null; },
  };
  document.addEventListener('visibilitychange', () => document.hidden ? window.dashLive.stop() : window.dashLive.start());

  function routerUnit(routerId, wans) {
    const POINTS = 30;
    return {
      routerId, wans,
      online: null, dl: '-', ul: '-', perWan: [],
      cpu: 0, ram: 0, uptime: '',
      init() {
        if (this._siap) return;
        this._siap = true;
        this.$nextTick(() => {
          this.initChart();
          window.dashLive.on(d => this.terima(d));
        });
      },
      fmt(v) { return window.ZeroNet.fmtBps(v); },
      initChart() {
        const labels = Array(POINTS).fill('');
        const opts = window.ZeroNet.baseLineOptions({
          y: { suggestedMax: 1000000 },
          tooltipCallbacks: { title: (items) => items.length ? (POINTS - items[0].dataIndex - 1) * 3 + ' dtk lalu' : '' },
        });
        this.$el._chart = window.ZeroNet.trackChart(new Chart(this.$refs.canvas.getContext('2d'), {
          type: 'line',
          data: {
            labels,
            datasets: [
              { label: 'Download', znSlot: 0, data: Array(POINTS).fill(null), fill: true },
              { label: 'Upload',   znSlot: 1, data: Array(POINTS).fill(null), fill: false },
            ],
          },
          options: opts,
          plugins: [window.ZeroNet.crosshairPlugin],
        }));
      },
      terima(data) {
        const r = data.gagal ? null : data.routers?.[this.routerId];
        const h = r?.health;
        if (h && h.online) {
          this.cpu = h.stats?.cpu_load ?? 0;
          this.ram = h.stats?.mem_pct ?? 0;
          this.uptime = this.fmtUptime(h.stats?.uptime ?? '');
        }
        const t = r?.traffic;
        if (!t || !t.online) {
          this.online = data.gagal ? this.online : false;
          if (!data.gagal) { this.dl = '-'; this.ul = '-'; this.cpu = 0; this.ram = 0; }
          return;
        }
        this.online = true;
        this.perWan = t.interfaces || [];
        if (this.perWan.length) this.wans = this.perWan.map(w => w.interface);
        if (!t.measured) return;
        const chart = this.$el._chart;
        if (chart) {
          chart.data.datasets[0].data.push(t.total_download); chart.data.datasets[0].data.shift();
          chart.data.datasets[1].data.push(t.total_upload);   chart.data.datasets[1].data.shift();
          chart.update('none');
        }
        this.dl = this.fmt(t.total_download);
        this.ul = this.fmt(t.total_upload);
      },
      fmtUptime(str) {
        if (!str) return '';
        const label = { w: 'mg', d: 'hr', h: 'j', m: 'm' };
        const parts = [];
        for (const [, num, unit] of String(str).matchAll(/(\d+)([wdhms])/g)) if (label[unit]) parts.push(num + label[unit]);
        return parts.slice(0, 2).join(' ');
      },
    };
  }
</script>
@endpush

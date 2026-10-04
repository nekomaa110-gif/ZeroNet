@extends('layouts.app')

@section('title', 'Throughput')
@section('page-title', 'Throughput')

@section('content')
<div x-data="throughputPage(@js($routers), @js($ranges))" x-init="init()" @tp-stats.window="setStats($event.detail)">

  <header class="page-head">
    <div>
      <h2>Throughput</h2>
      <p>Laju dan pemakaian data WAN tiap MikroTik. Data direkam otomatis tiap 5 menit.</p>
    </div>
    <div class="head-actions tp-head-actions">
      <div class="seg" role="group" aria-label="Rentang hari">
        <template x-for="d in ranges" :key="d">
          <button type="button" :class="range === d && 'active'" :aria-pressed="range === d ? 'true' : 'false'" @click="setRange(d)" x-text="d + ' hari'"></button>
        </template>
      </div>
    </div>
  </header>

  <section class="card card-pad tp-summary" x-show="routers.length" x-cloak>
    <div class="tp-sum-main">
      <span class="tp-label">Pemakaian hari ini, semua router</span>
      <b class="mono tp-sum-v" x-text="fmtGB(sum('today_rx') + sum('today_tx'))"></b>
      <span class="tp-muted mono">&darr; <span x-text="fmtGB(sum('today_rx'))"></span> &nbsp; &uarr; <span x-text="fmtGB(sum('today_tx'))"></span></span>
    </div>
    <div class="tp-sum-main">
      <span class="tp-label">Laju sekarang, semua router</span>
      <b class="mono tp-sum-v">&darr; <span x-text="fmtBps(sum('live_down'))"></span></b>
      <span class="tp-muted mono">&uarr; <span x-text="fmtBps(sum('live_up'))"></span></span>
    </div>
    <div class="tp-sum-routers">
      <template x-for="r in routers" :key="r.id">
        <div class="tp-sum-row">
          <span x-text="r.name"></span>
          <div class="tp-sum-bar"><i :style="'width:' + sharePct(r.id) + '%'"></i></div>
          <b class="mono" x-text="stats[r.id]?.has_data ? fmtGB((stats[r.id]?.today_rx ?? 0) + (stats[r.id]?.today_tx ?? 0)) : '-'"></b>
        </div>
      </template>
    </div>
  </section>

  <template x-if="!routers.length">
    <div class="card tp-empty">
      <b>Belum ada router</b>
      <span>Tambahkan router di Manajemen Router untuk mulai merekam throughput.</span>
    </div>
  </template>

  <template x-for="r in routers" :key="r.id">
    <section class="card tp-card" x-data="tpRouter(r)">
      <div class="card-head">
        <div>
          <h3>
            <a :href="r.router_url" x-text="r.name" class="tp-title"></a>
            <span class="badge" :class="online === null ? '' : (online ? 'ok' : 'err')" x-text="online === null ? 'Mengecek' : (online ? 'Online' : 'Offline')"></span>
            <span class="badge brand" x-show="r.lb">Load balance</span>
          </h3>
          <p class="mono" x-text="r.host + ' · WAN ' + (ifaces().join(', ') || '-')"></p>
        </div>
        <div class="ch-actions">
          <span class="tp-muted" x-show="live && live.time">Diperbarui <b class="mono" x-text="live?.time"></b></span>
        </div>
      </div>

      <div class="card-pad">
        <div class="tp-banner" x-show="online === false" x-cloak>
          Router tidak dapat dihubungi, laju sekarang tidak tersedia. Riwayat di bawah tetap dari data yang sudah terekam.
          <span class="mono" x-text="error"></span>
        </div>

        <div class="tp-stats">
          <div class="tp-stat">
            <span class="tp-label">Laju sekarang</span>
            <b class="mono">&darr; <span x-text="fmtBps(live?.total_download)"></span></b>
            <span class="tp-muted mono">&uarr; <span x-text="fmtBps(live?.total_upload)"></span></span>
          </div>
          <div class="tp-stat">
            <span class="tp-label">Hari ini</span>
            <b class="mono" x-text="fmtGB(dayTotal(today()))"></b>
            <span class="tp-muted mono">&darr; <span x-text="fmtGB(dayDir(today(), 'rx'))"></span> &nbsp; &uarr; <span x-text="fmtGB(dayDir(today(), 'tx'))"></span></span>
          </div>
          <div class="tp-stat">
            <span class="tp-label">Kemarin</span>
            <b class="mono" x-text="yesterday() ? fmtGB(dayTotal(yesterday())) : '-'"></b>
            <span class="tp-muted mono" x-show="yesterday()">&darr; <span x-text="fmtGB(dayDir(yesterday(), 'rx'))"></span> &nbsp; &uarr; <span x-text="fmtGB(dayDir(yesterday(), 'tx'))"></span></span>
            <span class="tp-muted" x-show="yesterday() && yesterday().partial">data sebagian</span>
          </div>
          <div class="tp-stat">
            <span class="tp-label" x-text="'Total ' + range + ' hari'"></span>
            <b class="mono" x-text="fmtGB(rangeTotal())"></b>
            <span class="tp-muted" x-text="avgLabel()"></span>
          </div>
        </div>

        <div class="tp-ifaces" x-show="(live?.interfaces ?? []).length > 1">
          <template x-for="(w, i) in live?.interfaces ?? []" :key="w.interface">
            <div class="tp-iface" :style="'--wan:' + colorOf(w.interface)">
              <span class="tp-dot"></span>
              <b class="mono" x-text="w.interface"></b>
              <span class="mono">&darr; <span x-text="fmtBps(w.download)"></span></span>
              <span class="mono tp-muted">&uarr; <span x-text="fmtBps(w.upload)"></span></span>
              <span class="badge no-dot err" x-show="w.exists && !w.running">link mati</span>
            </div>
          </template>
        </div>

        <div class="tp-charts" x-show="history && history.since">
          <div class="tp-chart-box">
            <div class="tp-chart-title">Throughput 24 jam terakhir</div>
            <div class="tp-chart-sub">Download</div>
            <div class="tp-chart tp-chart-dl"><canvas x-ref="tpDown"></canvas></div>
            <div class="tp-chart-sub">Upload</div>
            <div class="tp-chart tp-chart-ul"><canvas x-ref="tpUp"></canvas></div>
          </div>
          <div class="tp-chart-box">
            <div class="tp-chart-title" x-text="'Pemakaian per hari, ' + range + ' hari terakhir'"></div>
            <div class="tp-chart tp-chart-grow"><canvas x-ref="dayCanvas"></canvas></div>
          </div>
        </div>
        <div class="tp-nodata" x-show="history && !history.since">Belum ada data terekam untuk router ini. Perekam berjalan tiap 5 menit; grafik dan tabel muncul setelah dua sampel.</div>

        <div class="tp-days" x-show="history && history.days.length">
          <div class="tp-days-head">
            <span>Pemakaian data per hari</span>
            <span class="tp-muted" x-show="history?.since">Data sejak <b x-text="history?.since"></b></span>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <template x-for="ifc in history?.interfaces ?? []" :key="ifc">
                    <th class="num"><span class="tp-dot" :style="'--wan:' + colorOf(ifc)"></span> <span x-text="ifc"></span></th>
                  </template>
                  <th class="num">Download</th>
                  <th class="num">Upload</th>
                  <th class="num">Total</th>
                </tr>
              </thead>
              <tbody>
                <template x-for="d in daysDesc()" :key="d.date">
                  <tr>
                    <td>
                      <span class="mono" x-text="fmtDate(d.date)"></span>
                      <span class="tp-tag" x-show="d.date === todayKey()">berjalan</span>
                      <span class="tp-tag" x-show="d.partial && d.date !== todayKey()">sebagian</span>
                    </td>
                    <template x-for="ifc in history.interfaces" :key="ifc">
                      <td class="num mono" x-text="fmtGB(ifcTotal(d, ifc))"></td>
                    </template>
                    <td class="num mono" x-text="fmtGB(dayDir(d, 'rx'))"></td>
                    <td class="num mono" x-text="fmtGB(dayDir(d, 'tx'))"></td>
                    <td class="num mono"><b x-text="fmtGB(dayTotal(d))"></b></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </template>
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/chart.umd.min.js') }}"></script>
<script src="{{ asset('assets/zn-chart.js') }}?v={{ @filemtime(public_path('assets/zn-chart.js')) ?: 1 }}"></script>
<script>
  function tpColor(i) {
    const c = window.ZeroNet.chartColors();
    return i < c.series.length ? c.series[i] : c.other;
  }

  function tpTheme() {
    const c = window.ZeroNet.chartColors();
    return { tick: c.tick, grid: c.grid };
  }

  function tpHourStep() {
    return window.innerWidth < 640 ? 6 : 3;
  }

  function tpFmtBps(bps) {
    if (bps === null || bps === undefined) return '-';
    bps = Number(bps) || 0;
    if (bps >= 1e6) return (bps / 1e6).toFixed(1) + ' Mbps';
    if (bps >= 1e3) return (bps / 1e3).toFixed(0) + ' Kbps';
    return bps + ' bps';
  }

  function tpFmtGB(b) {
    b = Number(b) || 0;
    if (b >= 1e9) return (b / 1e9).toFixed(2) + ' GB';
    return (b / 1e6).toFixed(0) + ' MB';
  }

  function tpTodayKey() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function throughputPage(routers, ranges) {
    return {
      routers, ranges, range: 7, stats: {},
      init() {
        try { const v = Number(localStorage.getItem('tp-range')); if (ranges.includes(v)) this.range = v; } catch (e) {}
      },
      setRange(d) {
        this.range = d;
        try { localStorage.setItem('tp-range', String(d)); } catch (e) {}
        window.dispatchEvent(new CustomEvent('tp-range', { detail: d }));
      },
      setStats(s) { this.stats = { ...this.stats, [s.id]: { ...(this.stats[s.id] || {}), ...s } }; },
      sum(key) { return Object.values(this.stats).reduce((t, s) => t + (Number(s[key]) || 0), 0); },
      sharePct(id) {
        const all = this.sum('today_rx') + this.sum('today_tx');
        const s = this.stats[id] || {};
        return all > 0 ? ((s.today_rx || 0) + (s.today_tx || 0)) * 100 / all : 0;
      },
      fmtGB: tpFmtGB,
      fmtBps: tpFmtBps,
    };
  }

  function tpRouter(r) {
    return {
      live: null, history: null, online: null, error: '', range: 7, _inFlight: false,
      hidden: { tp: {}, day: {} },
      init() {
        try { const v = Number(localStorage.getItem('tp-range')); if ([7, 14, 30].includes(v)) this.range = v; } catch (e) {}
        this._onRange = (e) => { this.range = e.detail; this.loadHistory(); };
        window.addEventListener('tp-range', this._onRange);
        this.poll();
        this.loadHistory();
        this._live = setInterval(() => { if (!document.hidden) this.poll(); }, 10000);
        this._hist = setInterval(() => { if (!document.hidden) this.loadHistory(); }, 300000);
      },
      destroy() {
        clearInterval(this._live);
        clearInterval(this._hist);
        window.removeEventListener('tp-range', this._onRange);
        ['tpDown', 'tpUp', 'dayCanvas'].forEach(k => this.$refs[k]?._chart?.destroy());
      },
      ifaces() { return this.history?.interfaces?.length ? this.history.interfaces : r.interfaces; },
      colorOf(ifc) {
        const i = this.ifaces().indexOf(ifc);
        return tpColor(i < 0 ? 0 : i);
      },
      fmtGB: tpFmtGB,
      fmtBps: tpFmtBps,
      todayKey: tpTodayKey,
      fmtDate(s) { return new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' }); },
      days() { return this.history?.days ?? []; },
      daysDesc() { return this.days().slice().reverse(); },
      today() { return this.days().find(d => d.date === tpTodayKey()) || null; },
      yesterday() {
        const d = new Date(); d.setDate(d.getDate() - 1);
        const k = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        return this.days().find(x => x.date === k) || null;
      },
      dayDir(d, dir) { return d ? Object.values(d.bytes).reduce((t, b) => t + (b[dir] || 0), 0) : 0; },
      dayTotal(d) { return this.dayDir(d, 'rx') + this.dayDir(d, 'tx'); },
      ifcTotal(d, ifc) { const b = d.bytes[ifc] || {}; return (b.rx || 0) + (b.tx || 0); },
      rangeTotal() { return this.days().reduce((t, d) => t + this.dayTotal(d), 0); },
      avgLabel() {
        const penuh = this.days().filter(d => !d.partial);
        if (!penuh.length) return 'belum ada hari penuh';
        const avg = penuh.reduce((t, d) => t + this.dayTotal(d), 0) / penuh.length;
        return 'rata-rata ' + tpFmtGB(avg) + ' per hari penuh';
      },
      publish() {
        const t = this.today();
        window.dispatchEvent(new CustomEvent('tp-stats', { detail: {
          id: r.id,
          has_data: !!this.history?.since,
          today_rx: this.dayDir(t, 'rx'),
          today_tx: this.dayDir(t, 'tx'),
          live_down: this.live?.total_download ?? 0,
          live_up: this.live?.total_upload ?? 0,
        } }));
      },
      legendClick(group, charts) {
        return (e, item) => {
          const label = item.text;
          const sembunyi = !this.hidden[group][label];
          this.hidden[group][label] = sembunyi;
          charts().forEach(c => {
            if (!c) return;
            c.data.datasets.forEach((ds, i) => { if (ds.label === label) c.setDatasetVisibility(i, !sembunyi); });
            c.update('none');
          });
        };
      },
      lineChart(canvas, legend) {
        const th = tpTheme();
        canvas._chart = new Chart(canvas.getContext('2d'), {
          type: 'line',
          data: { labels: [], datasets: [] },
          options: {
            responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { right: 12 } },
            interaction: { intersect: false, mode: 'index' },
            scales: {
              x: { grid: { display: false }, ticks: { color: th.tick, font: { size: 10 }, maxRotation: 0, autoSkip: false,
                   callback: function (v) { const l = this.getLabelForValue(v); return l && l.endsWith(':00') && parseInt(l, 10) % tpHourStep() === 0 ? l : ''; } } },
              y: { beginAtZero: true, border: { display: false }, grid: { color: th.grid },
                   ticks: { color: th.tick, font: { size: 10 }, maxTicksLimit: 4, callback: v => tpFmtBps(v) } },
            },
            plugins: {
              legend: { display: legend, position: 'bottom', labels: { color: th.tick, boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } },
                        onClick: this.legendClick('tp', () => [this.$refs.tpDown?._chart, this.$refs.tpUp?._chart]) },
              tooltip: { filter: c => c.raw !== null, callbacks: { label: c => ' ' + c.dataset.label + ': ' + tpFmtBps(c.raw) } },
            },
          },
        });
      },
      dayChart() {
        const th = tpTheme();
        const canvas = this.$refs.dayCanvas;
        canvas._chart = new Chart(canvas.getContext('2d'), {
          type: 'bar',
          data: { labels: [], datasets: [] },
          options: {
            responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { right: 12 } },
            interaction: { intersect: false, mode: 'index' },
            scales: {
              x: { stacked: true, grid: { display: false }, ticks: { color: th.tick, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
              y: { stacked: true, beginAtZero: true, border: { display: false }, grid: { color: th.grid },
                   ticks: { color: th.tick, font: { size: 10 }, maxTicksLimit: 5, callback: v => (v / 1e9).toFixed(0) + ' GB' } },
            },
            plugins: {
              legend: { position: 'bottom', labels: { color: th.tick, boxWidth: 10, boxHeight: 10, padding: 10, font: { size: 11 } },
                        onClick: this.legendClick('day', () => [this.$refs.dayCanvas?._chart]) },
              tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + tpFmtGB(c.raw) } },
            },
          },
        });
      },
      ensureCharts() {
        if (!this.history?.since || this.$refs.tpDown?._chart) return;
        this.lineChart(this.$refs.tpDown, false);
        this.lineChart(this.$refs.tpUp, true);
        this.dayChart();
      },
      fillCharts() {
        const h = this.history;
        if (!h) return;
        this.ensureCharts();
        [['tpDown', 'rx'], ['tpUp', 'tx']].forEach(([ref, key]) => {
          const chart = this.$refs[ref]?._chart;
          if (!chart) return;
          chart.data.labels = h.throughput.labels;
          chart.data.datasets = h.interfaces.map(ifc => ({
            label: ifc,
            data: h.throughput.series[ifc]?.[key] ?? [],
            borderColor: this.colorOf(ifc), backgroundColor: this.colorOf(ifc) + '22',
            tension: 0.3, pointRadius: 0, pointHitRadius: 4, borderWidth: 1.8, fill: true, spanGaps: false,
            hidden: !!this.hidden.tp[ifc],
          }));
          chart.update('none');
        });
        const day = this.$refs.dayCanvas?._chart;
        if (day) {
          day.data.labels = h.days.map(d => new Date(d.date + 'T00:00:00').toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }));
          day.data.datasets = h.interfaces.flatMap(ifc => [
            { label: ifc + ' download', data: h.days.map(d => d.bytes[ifc]?.rx || 0), backgroundColor: this.colorOf(ifc), borderRadius: 2, maxBarThickness: 34, hidden: !!this.hidden.day[ifc + ' download'] },
            { label: ifc + ' upload', data: h.days.map(d => d.bytes[ifc]?.tx || 0), backgroundColor: this.colorOf(ifc) + '66', borderRadius: 2, maxBarThickness: 34, hidden: !!this.hidden.day[ifc + ' upload'] },
          ]);
          day.update('none');
        }
      },
      async poll() {
        if (this._inFlight) return;
        this._inFlight = true;
        const ctl = new AbortController();
        const to = setTimeout(() => ctl.abort(), 12000);
        try {
          const res = await fetch(r.live_url, { signal: ctl.signal, headers: { Accept: 'application/json' } });
          const d = await res.json();
          if (!d.online) { this.online = false; this.error = d.error || ''; this.live = null; }
          else { this.online = true; this.error = ''; this.live = d; }
        } catch (e) {
          this.online = false;
          this.error = e.name === 'AbortError' ? 'waktu habis' : e.message;
        } finally {
          clearTimeout(to);
          this._inFlight = false;
          this.publish();
        }
      },
      async loadHistory() {
        try {
          const res = await fetch(r.history_url + '?days=' + this.range, { headers: { Accept: 'application/json' } });
          this.history = await res.json();
          await this.$nextTick();
          this.fillCharts();
          this.publish();
        } catch (e) {
        }
      },
    };
  }
</script>
@endpush

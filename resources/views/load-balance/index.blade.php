@extends('layouts.app')

@section('title', 'Load Balance')
@section('page-title', 'Load Balance')

@section('content')
<div x-data="loadBalancePage('{{ route('load-balance.routers') }}')" x-init="init()" @lb-redetect.window="load()">

  <header class="page-head">
    <div>
      <h2>Load Balance</h2>
      <p>Router dideteksi otomatis dari rule PCC/nth di mangle atau default route ECMP. Router tanpa load balance tidak ditampilkan.</p>
    </div>
    <div class="head-actions lb-head-actions">
      <span class="lb-muted" x-show="time" x-cloak>Sinkron <b class="mono" x-text="time"></b></span>
      <button class="btn btn-sm" @click="load()" :disabled="loading">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="loading ? 'animation: spin 1s linear infinite' : ''"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Deteksi ulang
      </button>
    </div>
  </header>

  <template x-if="loading && !ready">
    <div class="card card-pad">
      <div class="skeleton" style="height:18px;width:40%;margin-bottom:14px"></div>
      <div class="skeleton" style="height:12px;margin-bottom:22px"></div>
      <div class="lb-wans">
        <div class="skeleton" style="height:120px"></div>
        <div class="skeleton" style="height:120px"></div>
      </div>
    </div>
  </template>

  <template x-if="ready && error">
    <div class="card card-pad lb-banner err">
      <b>Gagal mendeteksi router.</b> <span x-text="error"></span>
    </div>
  </template>

  <template x-if="ready && !error && routers.length === 0">
    <div class="card lb-empty">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h7l3 5h6"/><path d="M4 17h7l3-5"/><polyline points="17 9 20 12 17 15"/></svg>
      <b>Belum ada router yang memakai load balance</b>
      <span>Dicek <span x-text="checked"></span> router. Halaman ini otomatis menampilkan router begitu PCC, nth, atau ECMP terpasang.</span>
      <span x-show="unreachable.length" class="lb-warn-text">Tidak bisa dihubungi: <span x-text="unreachable.join(', ')"></span></span>
    </div>
  </template>

  <template x-if="ready && routers.length && unreachable.length">
    <p class="lb-muted" style="margin:0 0 14px">Tidak bisa dicek saat ini: <span x-text="unreachable.join(', ')"></span></p>
  </template>

  <template x-for="r in routers" :key="r.id">
    <section class="card lb-card" x-data="lbRouter(r)">
      <div class="card-head">
        <div>
          <h3>
            <a :href="r.router_url" x-text="r.name" class="lb-title"></a>
            <span class="badge" :class="online ? 'ok' : 'err'" x-text="online ? 'Online' : 'Offline'"></span>
            <span class="badge" :class="enabled() ? 'brand' : 'warn'" x-text="enabled() ? 'Load balance aktif' : 'Rule dimatikan'"></span>
          </h3>
          <p class="mono" x-text="r.host + ' · ' + methodLabel()"></p>
        </div>
        <div class="ch-actions">
          <span class="lb-muted" x-show="live && live.time">Diperbarui <b class="mono" x-text="live?.time"></b></span>
          <span class="lb-muted" x-show="live && live.cpu !== undefined">CPU <b class="mono" x-text="(live?.cpu ?? 0) + '%'"></b></span>
          <button type="button" class="btn btn-sm" x-show="canEdit()" @click="openEdit('balance')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            Atur
          </button>
        </div>
      </div>

      <div class="card-pad">
        <div class="lb-banner err" x-show="!online" x-cloak>
          Router tidak dapat dihubungi. Data di bawah adalah konfigurasi terakhir yang terdeteksi<span x-show="r.detected_at"> (<span x-text="fmtTime(r.detected_at)"></span>)</span>.
          <span x-show="error" class="mono" x-text="error"></span>
        </div>
        <div class="lb-banner warn" x-show="online && !enabled()" x-cloak>
          Rule pembagi (PCC/nth) sedang dimatikan, jadi koneksi baru tidak dibagi dan kembali ke rute utama.
        </div>

        <div class="lb-split-head">
          <span>Porsi download saat ini</span>
          <span class="lb-muted">Target <b x-text="targetLabel()"></b></span>
        </div>
        <div class="lb-split-labels" aria-hidden="true">
          <template x-for="(w, i) in wans()" :key="w.label">
            <div class="lb-split-label" :style="'width:' + labelWidth(w) + '%;--wan:' + color(i)">
              <span class="lb-dot"></span><span class="mono" x-text="w.label"></span><b class="mono" x-text="pct(w.share_down)"></b>
            </div>
          </template>
        </div>
        <div class="lb-split" :aria-label="'Porsi download ' + shareLabel()">
          <template x-for="(w, i) in wans()" :key="w.label">
            <div class="lb-seg" :style="'width:' + segWidth(w) + '%;background:' + color(i)"></div>
          </template>
          <template x-for="m in targetMarks()" :key="m">
            <i class="lb-mark" :style="'left:' + m + '%'"></i>
          </template>
          <div class="lb-split-empty" x-show="!hasShare()">Mengukur laju...</div>
        </div>

        <div class="lb-wans">
          <template x-for="(w, i) in wans()" :key="w.label">
            <div class="lb-wan" :style="'--wan:' + color(i)">
              <div class="lb-wan-head">
                <span class="lb-dot"></span>
                <b class="mono" x-text="w.label"></b>
                <span class="badge no-dot" :class="stateClass(w)" x-text="stateLabel(w)"></span>
              </div>
              <div class="lb-wan-rate mono">
                <span>&darr; <b x-text="fmtBps(w.download)"></b></span>
                <span class="lb-up">&uarr; <span x-text="fmtBps(w.upload)"></span></span>
              </div>
              <dl class="lb-wan-meta">
                <div><dt>Porsi download</dt><dd class="mono" x-text="pct(w.share_down)"></dd></div>
                <div><dt>Target</dt><dd class="mono" x-text="pct(w.target_pct)"></dd></div>
                <div x-show="w.conn !== null && w.conn !== undefined"><dt>Koneksi</dt><dd class="mono" x-text="(w.conn ?? 0) + ' (' + pct(w.share_conn) + ')'"></dd></div>
                <div><dt>Gateway</dt><dd class="mono" x-text="w.gateway || '-'"></dd></div>
              </dl>
            </div>
          </template>
        </div>

        <template x-for="rd in (live?.radius ?? [])" :key="rd.address">
          <div class="lb-radius">
            <span class="lb-radius-title">Jalur RADIUS</span>
            <span class="mono" x-text="rd.address"></span>
            <span>Main route <b class="mono" x-text="rd.via || '-'"></b></span>
            <span x-show="rd.backup.length">Backup route <b class="mono" x-text="rd.backup.join(', ')"></b></span>
            <span class="badge no-dot" :class="radiusClass(rd)" x-text="radiusLabel(rd)"></span>
            <button type="button" class="btn btn-sm lb-radius-edit" x-show="canEdit()" @click="openEdit('radius')">Atur</button>
          </div>
        </template>

        <div class="lb-charts">
          <div class="lb-chart-box">
            <div class="lb-chart-title">Throughput per WAN, 24 jam terakhir</div>
            <div class="lb-chart-sub">Download</div>
            <div class="lb-chart lb-chart-dl"><canvas x-ref="tpDown"></canvas></div>
            <div class="lb-chart-sub">Upload</div>
            <div class="lb-chart lb-chart-ul"><canvas x-ref="tpUp"></canvas></div>
          </div>
          <div class="lb-chart-box">
            <div class="lb-chart-title">Pemakaian per jam, 24 jam terakhir (download + upload)</div>
            <div class="lb-chart lb-chart-grow"><canvas x-ref="hourCanvas"></canvas></div>
            <div class="lb-muted lb-chart-note" x-show="history && !history.since">Belum ada sampel. Perekam berjalan tiap 5 menit, grafik terisi setelah dua sampel.</div>
          </div>
        </div>

        <div class="lb-days" x-show="history && history.days.length">
          <div class="lb-split-head">
            <span>Pembagian kuota per hari (download + upload)</span>
            <span class="lb-muted" x-show="history?.since">Data sejak <b x-text="history?.since"></b></span>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <template x-for="(ifc, i) in history?.interfaces ?? []" :key="ifc">
                    <th class="num"><span class="lb-dot" :style="'--wan:' + colorOf(ifc)"></span> <span x-text="ifc"></span></th>
                  </template>
                  <th class="num">Total</th>
                </tr>
              </thead>
              <tbody>
                <template x-for="d in daysDesc()" :key="d.date">
                  <tr>
                    <td class="mono" x-text="fmtDate(d.date)"></td>
                    <template x-for="ifc in history.interfaces" :key="ifc">
                      <td class="num mono">
                        <span x-text="fmtGB(dayBytes(d, ifc))"></span>
                        <span class="lb-muted" x-text="'(' + pct(dayShare(d, ifc)) + ')'"></span>
                      </td>
                    </template>
                    <td class="num mono" x-text="fmtGB(dayTotal(d))"></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <template x-teleport="body">
        <div class="mdl-backdrop is-open" x-show="edit.open" x-transition.opacity @keydown.escape.window="edit.open && !edit.applying && closeEdit()" @click.self="!edit.applying && closeEdit()" role="dialog" aria-modal="true" x-cloak>
          <div class="mdl mdl-md">
            <div class="mdl-head lbm-head">
              <div class="mdl-title-wrap">
                <h3 x-text="'Atur ' + r.name"></h3>
                <p>Perintah RouterOS ditampilkan dulu sebelum diterapkan.</p>
              </div>
              <button type="button" class="mdl-close" @click="closeEdit()" :disabled="edit.applying" aria-label="Tutup">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>

            <div class="tabs lbm-tabs" role="tablist">
              <button type="button" :class="edit.tab === 'balance' && 'active'" @click="switchTab('balance')">Load balance</button>
              <button type="button" :class="edit.tab === 'radius' && 'active'" @click="switchTab('radius')">Jalur RADIUS</button>
            </div>

            <div class="mdl-body lbm-body">
              <div x-show="edit.loading" class="lbm-note">Membaca konfigurasi router...</div>
              <div x-show="edit.error" class="lb-banner err" x-text="edit.error"></div>

              <template x-if="edit.cfg && edit.tab === 'balance'">
                <div>
                  <template x-if="!edit.cfg.balance.editable">
                    <div class="lb-banner warn" x-text="edit.cfg.balance.reason"></div>
                  </template>
                  <template x-if="edit.cfg.balance.editable">
                    <div class="lbm-form">
                      <label class="lbm-check">
                        <input type="checkbox" x-model="edit.form.enabled" @change="resetPreview()">
                        <span>Load balance aktif</span>
                      </label>
                      <div class="field">
                        <label>Classifier PCC</label>
                        <select class="select" x-model="edit.form.classifier" @change="resetPreview()">
                          <template x-for="c in edit.cfg.classifiers" :key="c">
                            <option :value="c" x-text="c" :selected="c === edit.form.classifier"></option>
                          </template>
                        </select>
                        <small class="lbm-hint" x-text="classifierHint()"></small>
                      </div>
                      <div class="field">
                        <label>Pembagian per WAN</label>
                        <div class="lbm-presets" x-show="edit.cfg.wans.length === 2">
                          <template x-for="p in presets" :key="p.join(':')">
                            <button type="button" class="btn btn-sm" @click="applyPreset(p)" x-text="p.join(' : ')"></button>
                          </template>
                        </div>
                        <template x-for="(w, i) in edit.cfg.wans" :key="w.interface">
                          <div class="lbm-weight" :style="'--wan:' + color(i)">
                            <span class="lb-dot"></span>
                            <b class="mono" x-text="w.interface"></b>
                            <input type="number" class="input mono" min="0" :max="edit.cfg.max_buckets" x-model.number="edit.form.weights[w.interface]" @input="resetPreview()">
                            <span class="mono lbm-pct" x-text="weightPct(w.interface)"></span>
                          </div>
                        </template>
                        <small class="lbm-hint">Bobot disederhanakan otomatis, maksimal <span x-text="edit.cfg.max_buckets"></span> bagian. Hanya koneksi baru yang mengikuti pembagian baru; koneksi berjalan tidak diputus.</small>
                      </div>
                    </div>
                  </template>
                </div>
              </template>

              <template x-if="edit.cfg && edit.tab === 'radius'">
                <div>
                  <template x-if="!edit.cfg.radius.length">
                    <div class="lb-banner warn">Router ini tidak memakai server RADIUS.</div>
                  </template>
                  <template x-for="rad in edit.cfg.radius" :key="rad.address">
                    <div class="lbm-form">
                      <div class="lbm-note">Server RADIUS <b class="mono" x-text="rad.address"></b>. Tunnel L2TP ke panel ikut jalur ini.</div>
                      <div class="field">
                        <label>Main route</label>
                        <template x-for="(w, i) in edit.cfg.wans" :key="w.interface">
                          <label class="lbm-radio" :style="'--wan:' + color(i)">
                            <input type="radio" :value="w.interface" x-model="edit.form.main" @change="resetPreview()">
                            <span class="lb-dot"></span>
                            <b class="mono" x-text="w.interface"></b>
                            <span class="lbm-hint" x-text="w.interface === edit.form.main ? 'main route' : 'backup route'"></span>
                          </label>
                        </template>
                      </div>
                      <template x-if="!rad.routes.length">
                        <div class="lbm-note">Belum ada rute khusus ke server ini. Pratinjau akan membuat main route dan backup route baru dengan pengecekan ping.</div>
                      </template>
                      <template x-if="rad.routes.length">
                        <table class="tbl lbm-routes">
                          <thead><tr><th>Rute</th><th>WAN</th><th class="num">Distance</th><th>Status</th></tr></thead>
                          <tbody>
                            <template x-for="x in rad.routes" :key="x.comment + x.interface">
                              <tr>
                                <td class="mono" x-text="x.comment || '-'"></td>
                                <td class="mono" x-text="x.interface || '-'"></td>
                                <td class="num mono" x-text="x.distance"></td>
                                <td><span class="badge no-dot" :class="x.active ? 'ok' : ''" x-text="x.active ? 'aktif' : 'siaga'"></span></td>
                              </tr>
                            </template>
                          </tbody>
                        </table>
                      </template>
                    </div>
                  </template>
                </div>
              </template>

              <template x-if="edit.preview">
                <div class="lbm-preview">
                  <div class="lbm-preview-head">
                    <b>Perintah yang akan dijalankan</b>
                    <span class="lb-muted" x-text="edit.preview.commands.length + ' perintah'"></span>
                  </div>
                  <template x-if="!edit.preview.commands.length">
                    <div class="lbm-note">Tidak ada perubahan. Konfigurasi router sudah sesuai.</div>
                  </template>
                  <pre x-show="edit.preview.commands.length" class="mono" x-text="edit.preview.commands.join('\n')"></pre>
                  <div class="lb-banner warn" x-show="edit.preview.moves_tunnel">
                    Main route pindah WAN. Tunnel L2TP ke panel ikut pindah, jadi panel kehilangan koneksi ke router sekitar 1 sampai 2 menit. Login RADIUS pelanggan tetap jalan lewat WAN yang baru.
                  </div>
                </div>
              </template>

              <template x-if="edit.result">
                <div class="lb-banner" :class="edit.result.ok ? 'ok' : 'warn'">
                  <b x-text="edit.result.message"></b>
                  <template x-if="edit.result.done && edit.result.done.length">
                    <pre class="mono" x-text="edit.result.done.join('\n')"></pre>
                  </template>
                </div>
              </template>
            </div>

            <div class="mdl-foot lbm-foot">
              <button type="button" class="btn" @click="closeEdit()" :disabled="edit.applying">Tutup</button>
              <button type="button" class="btn" @click="previewEdit()" :disabled="!canPreview() || edit.busy">Pratinjau perintah</button>
              <button type="button" class="btn btn-primary" @click="applyEdit()" :disabled="!edit.preview || !edit.preview.commands.length || edit.busy" x-text="edit.applying ? 'Menerapkan...' : 'Terapkan'"></button>
            </div>
          </div>
        </div>
      </template>
    </section>
  </template>
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/chart.umd.min.js') }}"></script>
<script src="{{ asset('assets/zn-chart.js') }}?v={{ @filemtime(public_path('assets/zn-chart.js')) ?: 1 }}"></script>
<script>
  function lbColor(i) {
    const c = window.ZeroNet.chartColors();
    return i < c.series.length ? c.series[i] : c.other;
  }
  const LB_ADMIN = @json((auth()->user()->role ?? null) === 'admin');
  const LB_CLASSIFIER_HINT = {
    'both-addresses': 'Satu pelanggan ke satu server selalu lewat WAN yang sama. Paling aman untuk situs yang mengikat sesi ke IP.',
    'both-addresses-and-ports': 'Paling rata, tapi satu pelanggan bisa keluar lewat dua IP publik ke server yang sama.',
    'src-address': 'Satu pelanggan selalu lewat satu WAN. Kurang rata kalau pelanggan sedikit.',
    'dst-address': 'Satu server tujuan selalu lewat satu WAN.',
  };

  async function lbPost(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { status: res.status, data };
  }

  function lbTheme() {
    const c = window.ZeroNet.chartColors();
    return { tick: c.tick, grid: c.grid };
  }

  function lbHourStep() {
    return window.innerWidth < 640 ? 6 : 3;
  }

  function lbFmtBps(bps) {
    bps = Number(bps) || 0;
    if (bps >= 1e6) return (bps / 1e6).toFixed(1) + ' Mbps';
    if (bps >= 1e3) return (bps / 1e3).toFixed(0) + ' Kbps';
    return bps + ' bps';
  }

  function loadBalancePage(url) {
    return {
      url, loading: true, ready: false, routers: [], checked: 0, unreachable: [], error: '', time: '',
      init() {
        this.load();
        this._timer = setInterval(() => { if (!document.hidden) this.load(); }, 60000);
      },
      destroy() { clearInterval(this._timer); },
      async load() {
        this.loading = true;
        try {
          const res = await fetch(this.url, { headers: { Accept: 'application/json' } });
          const d = await res.json();
          this.routers = d.routers ?? [];
          this.checked = d.checked ?? 0;
          this.unreachable = d.unreachable ?? [];
          this.time = d.time ?? '';
          this.error = '';
        } catch (e) {
          this.error = e.message;
        } finally {
          this.loading = false;
          this.ready = true;
        }
      },
    };
  }

  function lbRouter(r) {
    return {
      live: null, history: null, online: r.online, error: r.error,
      _inFlight: false, _first: true,
      presets: [[50, 50], [60, 40], [40, 60], [70, 30], [30, 70]],
      hidden: { tp: {}, hour: {} },
      edit: {
        open: false, tab: 'balance', loading: false, busy: false, applying: false, error: '',
        cfg: null, preview: null, result: null,
        form: { enabled: true, classifier: '', weights: {}, main: '' },
      },
      canEdit() { return LB_ADMIN && !!r.config_url && this.online; },
      openEdit(tab) {
        this.edit.open = true;
        this.edit.tab = tab;
        this.edit.preview = null;
        this.edit.result = null;
        this.edit.error = '';
        this.loadConfig();
      },
      closeEdit() {
        if (this.edit.applying) return;
        this.edit.open = false;
      },
      switchTab(tab) {
        this.edit.tab = tab;
        this.edit.preview = null;
        this.edit.result = null;
      },
      resetPreview() { this.edit.preview = null; this.edit.result = null; },
      async loadConfig() {
        this.edit.loading = true;
        this.edit.cfg = null;
        try {
          const res = await fetch(r.config_url, { headers: { Accept: 'application/json' } });
          const d = await res.json();
          if (!d.ok) { this.edit.error = d.message || 'Gagal membaca konfigurasi.'; return; }
          this.edit.cfg = d;
          const b = d.balance || {};
          this.edit.form.enabled = b.editable ? !!b.enabled : true;
          this.edit.form.classifier = b.classifier || d.classifiers[0];
          this.edit.form.weights = { ...(b.weights || {}) };
          d.wans.forEach(w => { if (this.edit.form.weights[w.interface] === undefined) this.edit.form.weights[w.interface] = 0; });
          this.edit.form.main = d.radius[0]?.main || d.wans[0]?.interface || '';
        } catch (e) {
          this.edit.error = e.message;
        } finally {
          this.edit.loading = false;
        }
      },
      classifierHint() { return LB_CLASSIFIER_HINT[this.edit.form.classifier] || ''; },
      applyPreset(p) {
        const g = (a, b) => b ? g(b, a % b) : a;
        const d = g(p[0], p[1]);
        const wans = this.edit.cfg.wans;
        this.edit.form.weights[wans[0].interface] = p[0] / d;
        this.edit.form.weights[wans[1].interface] = p[1] / d;
        this.resetPreview();
      },
      weightPct(ifc) {
        const w = this.edit.form.weights;
        const total = Object.values(w).reduce((s, v) => s + (Number(v) || 0), 0);
        return total > 0 ? Math.round((Number(w[ifc]) || 0) * 1000 / total) / 10 + '%' : '-';
      },
      canPreview() {
        const c = this.edit.cfg;
        if (!c) return false;
        return this.edit.tab === 'balance' ? c.balance.editable : c.radius.length > 0;
      },
      payload() {
        if (this.edit.tab === 'radius') return { type: 'radius', main: this.edit.form.main };
        const weights = {};
        Object.entries(this.edit.form.weights).forEach(([k, v]) => { weights[k] = Number(v) || 0; });
        return { type: 'balance', classifier: this.edit.form.classifier, enabled: !!this.edit.form.enabled, weights };
      },
      async previewEdit() {
        this.edit.busy = true;
        this.edit.error = '';
        this.edit.result = null;
        try {
          const { data } = await lbPost(r.preview_url, this.payload());
          if (data.ok) this.edit.preview = data;
          else { this.edit.preview = null; this.edit.error = data.message || 'Pratinjau gagal.'; }
        } catch (e) {
          this.edit.error = e.message;
        } finally {
          this.edit.busy = false;
        }
      },
      async applyEdit() {
        const p = this.edit.preview;
        if (!p || !p.commands.length) return;
        const res = await window.zeroConfirm({
          title: 'Terapkan ke ' + r.name,
          message: p.commands.length + ' perintah akan dijalankan di router.' + (p.moves_tunnel ? ' Tunnel L2TP ke panel akan putus sambung sekitar 1 sampai 2 menit.' : ''),
          action: 'Terapkan',
          variant: 'warning',
          requirePassword: true,
          intent: 'mengubah load balance ' + r.name,
        });
        if (!res || !res.ok) return;
        this.edit.busy = true;
        this.edit.applying = true;
        this.edit.error = '';
        try {
          const { status, data } = await lbPost(r.apply_url, { ...this.payload(), hash: p.hash, current_password: res.password });
          this.edit.result = { ok: !!data.ok, message: data.message || ('Gagal (HTTP ' + status + ').'), done: data.done || [] };
          this.edit.preview = null;
          if (data.ok && !p.moves_tunnel) this.loadConfig();
          window.dispatchEvent(new CustomEvent('lb-redetect'));
          this.poll();
        } catch (e) {
          this.edit.result = { ok: false, message: 'Tidak ada jawaban dari server: ' + e.message, done: [] };
        } finally {
          this.edit.busy = false;
          this.edit.applying = false;
        }
      },
      init() {
        this.$nextTick(() => {
          this.makeCharts();
          this.poll();
          this.loadHistory();
          this._live = setInterval(() => { if (!document.hidden) this.poll(); }, 5000);
          this._hist = setInterval(() => { if (!document.hidden) this.loadHistory(); }, 300000);
        });
      },
      destroy() {
        clearInterval(this._live);
        clearInterval(this._hist);
        this.$refs.tpDown?._chart?.destroy();
        this.$refs.tpUp?._chart?.destroy();
        this.$refs.hourCanvas?._chart?.destroy();
      },
      wans() {
        const base = r.wans;
        if (!this.live || !this.live.wans) return base.map(w => ({ ...w, download: 0, upload: 0, share_down: null, share_conn: null, conn: null, state: null }));
        return this.live.wans;
      },
      enabled() { return this.live ? this.live.enabled : r.enabled; },
      color(i) { return lbColor(i); },
      colorOf(ifc) {
        const i = r.wans.findIndex(w => w.interface === ifc);
        return this.color(i < 0 ? r.wans.length : i);
      },
      methodLabel() {
        if (r.method === 'ecmp') return 'ECMP ' + r.wans.length + ' gateway';
        if (r.method === 'nth') return 'nth ' + r.denominator;
        return 'PCC ' + (r.classifier || '') + ' /' + r.denominator;
      },
      targetLabel() { return r.wans.map(w => Math.round(w.target_pct)).join(' : '); },
      targetMarks() {
        const marks = [];
        let acc = 0;
        r.wans.slice(0, -1).forEach(w => { acc += Number(w.target_pct) || 0; marks.push(acc); });
        return marks;
      },
      hasShare() { return this.wans().some(w => w.share_down !== null && w.share_down !== undefined); },
      segWidth(w) { return this.hasShare() ? (Number(w.share_down) || 0) : 0; },
      labelWidth(w) { return this.hasShare() ? this.segWidth(w) : (Number(w.target_pct) || 100 / r.wans.length); },
      shareLabel() { return this.wans().map(w => w.label + ' ' + this.pct(w.share_down)).join(', '); },
      stateLabel(w) {
        if (!this.online) return 'Tidak diketahui';
        if (w.running === false) return 'Link mati';
        if (w.state === 'failover') return 'Failover ke ' + (w.via || '?');
        if (w.state === 'down') return 'Rute putus';
        if (w.state === 'normal') return 'Normal';
        return 'Mengecek';
      },
      stateClass(w) {
        if (!this.online || w.running === false || w.state === 'down') return 'err';
        if (w.state === 'failover') return 'warn';
        if (w.state === 'normal') return 'ok';
        return '';
      },
      radiusLabel(rd) {
        if (!rd.via) return 'Putus';
        if (rd.on_backup) return 'Sedang lewat cadangan';
        if (rd.protected) return 'Failover otomatis';
        return rd.backup.length ? 'Cadangan hanya saat kabel putus' : 'Tanpa cadangan';
      },
      radiusClass(rd) {
        if (!rd.via) return 'err';
        if (rd.on_backup || !rd.protected) return 'warn';
        return 'ok';
      },
      pct(v) { return v === null || v === undefined ? '-' : (Math.round(v * 10) / 10) + '%'; },
      fmtBps: lbFmtBps,
      fmtGB(b) {
        b = Number(b) || 0;
        if (b >= 1e9) return (b / 1e9).toFixed(2) + ' GB';
        return (b / 1e6).toFixed(0) + ' MB';
      },
      fmtTime(iso) { try { return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return iso; } },
      fmtDate(s) { return new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' }); },
      daysDesc() { return (this.history?.days ?? []).slice().reverse(); },
      dayBytes(d, ifc) { const b = d.bytes[ifc] || {}; return (b.rx || 0) + (b.tx || 0); },
      dayTotal(d) { return (this.history?.interfaces ?? []).reduce((s, ifc) => s + this.dayBytes(d, ifc), 0); },
      dayShare(d, ifc) { const t = this.dayTotal(d); return t > 0 ? this.dayBytes(d, ifc) * 100 / t : null; },
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
      tpChart(canvas, legend) {
        const th = lbTheme();
        canvas._chart = new Chart(canvas.getContext('2d'), {
          type: 'line',
          data: { labels: [], datasets: [] },
          options: {
            responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { right: 12 } },
            interaction: { intersect: false, mode: 'index' },
            scales: {
              x: { grid: { display: false }, ticks: { color: th.tick, font: { size: 10 }, maxRotation: 0, autoSkip: false,
                   callback: function (v) { const l = this.getLabelForValue(v); return l && l.endsWith(':00') && parseInt(l, 10) % lbHourStep() === 0 ? l : ''; } } },
              y: { beginAtZero: true, border: { display: false }, grid: { color: th.grid },
                   ticks: { color: th.tick, font: { size: 10 }, maxTicksLimit: 4, callback: v => lbFmtBps(v) } },
            },
            plugins: {
              legend: { display: legend, position: 'bottom', labels: { color: th.tick, boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } },
                        onClick: this.legendClick('tp', () => [this.$refs.tpDown?._chart, this.$refs.tpUp?._chart]) },
              tooltip: { filter: c => c.raw !== null, callbacks: { label: c => ' ' + c.dataset.label + ': ' + lbFmtBps(c.raw) } },
            },
          },
        });
      },
      fillTp(canvas, key) {
        const chart = canvas?._chart;
        const tp = this.history?.throughput;
        if (!chart || !tp) return;
        chart.data.labels = tp.labels;
        chart.data.datasets = this.history.interfaces.map(ifc => ({
          label: ifc,
          data: tp.series[ifc]?.[key] ?? [],
          borderColor: this.colorOf(ifc), backgroundColor: this.colorOf(ifc) + '22',
          tension: 0.3, pointRadius: 0, pointHitRadius: 4, borderWidth: 1.8, fill: true, spanGaps: false,
          hidden: !!this.hidden.tp[ifc],
        }));
        chart.update('none');
      },
      makeCharts() {
        const th = lbTheme();
        this.tpChart(this.$refs.tpDown, false);
        this.tpChart(this.$refs.tpUp, true);
        const hourCtx = this.$refs.hourCanvas.getContext('2d');
        this.$refs.hourCanvas._chart = new Chart(hourCtx, {
          type: 'bar',
          data: { labels: [], datasets: [] },
          options: {
            responsive: true, maintainAspectRatio: false, animation: false, layout: { padding: { right: 12 } },
            interaction: { intersect: false, mode: 'index' },
            scales: {
              x: { stacked: true, grid: { display: false }, ticks: { color: th.tick, font: { size: 10 }, maxRotation: 0, autoSkip: false,
                   callback: function (v) { const l = this.getLabelForValue(v); return parseInt(l, 10) % lbHourStep() === 0 ? l : ''; } } },
              y: { stacked: true, beginAtZero: true, border: { display: false }, grid: { color: th.grid },
                   ticks: { color: th.tick, font: { size: 10 }, maxTicksLimit: 4, callback: v => (v / 1e9).toFixed(1) + ' GB' } },
            },
            plugins: {
              legend: { position: 'bottom', labels: { color: th.tick, boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } },
                        onClick: this.legendClick('hour', () => [this.$refs.hourCanvas?._chart]) },
              tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + this.fmtGB(c.raw) } },
            },
          },
        });
      },
      async poll() {
        if (this._inFlight) return;
        this._inFlight = true;
        const ctl = new AbortController();
        const to = setTimeout(() => ctl.abort(), 12000);
        try {
          const res = await fetch(r.live_url, { signal: ctl.signal, headers: { Accept: 'application/json' } });
          const d = await res.json();
          if (!d.online) { this.online = false; this.error = d.error || ''; return; }
          if (d.lb === false) { window.dispatchEvent(new CustomEvent('lb-redetect')); return; }
          this.online = true;
          this.error = '';
          this.live = d;
        } catch (e) {
          this.online = false;
          this.error = e.name === 'AbortError' ? 'waktu habis' : e.message;
        } finally {
          clearTimeout(to);
          this._inFlight = false;
        }
      },
      async loadHistory() {
        try {
          const res = await fetch(r.history_url, { headers: { Accept: 'application/json' } });
          this.history = await res.json();
          this.fillTp(this.$refs.tpDown, 'rx');
          this.fillTp(this.$refs.tpUp, 'tx');
          const chart = this.$refs.hourCanvas?._chart;
          if (!chart) return;
          chart.data.labels = this.history.hours.map(h => h.hour);
          chart.data.datasets = this.history.interfaces.map(ifc => ({
            label: ifc,
            data: this.history.hours.map(h => h.bytes[ifc] || 0),
            backgroundColor: this.colorOf(ifc),
            borderRadius: 3,
            maxBarThickness: 18,
            hidden: !!this.hidden.hour[ifc],
          }));
          chart.update('none');
        } catch (e) {
        }
      },
    };
  }
</script>
@endpush

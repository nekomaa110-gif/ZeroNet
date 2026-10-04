@extends('layouts.app')

@section('title', 'Manajemen Router')
@section('page-title', 'Manajemen Router')

@php $isAdmin = (auth()->user()->role ?? null) === 'admin'; @endphp

@section('page-class', 'pg-routers-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Manajemen Router</h2>
      <p>Monitoring user aktif dan kelola MikroTik.</p>
    </div>
    @if ($isAdmin)
      <div class="head-actions">
        <button type="button" class="btn btn-primary" data-router-add>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Router
        </button>
      </div>
    @endif
  </header>

  <section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px">
    @forelse ($routers as $router)
      <div class="router-cell">
      <a href="{{ route('routers.show', $router['id']) }}"
         x-data="routerCard('{{ route('routers.stats', $router['id']) }}', '{{ $isAdmin ? route('routers.kesehatan', $router['id']) : '' }}')"
         x-init="load()"
         class="router-card">

        <div class="rc-head">
          <div class="router-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M15 10v4"/><path d="M17.84 7.17a4 4 0 0 0-5.66 0"/></svg>
          </div>
          <div>
            <h3>{{ $router['name'] }}</h3>
            <p class="mono">{{ $router['host'] }}:{{ $router['port'] }}</p>
          </div>
          <template x-if="loading"><span class="badge push-end">Mengecek…</span></template>
          <template x-if="!loading && online"><span class="badge ok push-end">Online</span></template>
          <template x-if="!loading && !online"><span class="badge err push-end">Offline</span></template>
        </div>

        <template x-if="loading">
          <div>
            <div class="rc-meta">
              <div><span>Identitas</span><b></b></div>
              <div><span>Waktu Aktif</span><b class="mono"></b></div>
              <div><span>Versi</span><b class="mono"></b></div>
            </div>
            <div class="rc-bars">
              <div class="mb"><span>CPU</span><div class="progress"><i></i></div><b class="mono">--%</b></div>
              <div class="mb"><span>RAM</span><div class="progress"><i></i></div><b class="mono">--%</b></div>
              <div class="mb"><span>Disk</span><div class="progress"><i></i></div><b class="mono">--%</b></div>
            </div>
          </div>
        </template>

        <template x-if="!loading && online">
          <div>
            <div class="rc-meta">
              <div><span>Identitas</span><b x-text="stats.identity || ''"></b></div>
              <div><span>Waktu Aktif</span><b class="mono" x-text="fmtUptime(stats.uptime)"></b></div>
              <div><span>Versi</span><b class="mono" x-text="stats.version ? 'ROS ' + stats.version : ''"></b></div>
            </div>
            <div class="rc-bars">
              <div class="mb"><span>CPU</span>
                <div class="progress" :class="stats.cpu_load > 80 ? 'err' : stats.cpu_load > 50 ? 'warn' : 'ok'">
                  <i :style="'width:' + stats.cpu_load + '%'"></i>
                </div>
                <b class="mono" x-text="stats.cpu_load + '%'"></b>
              </div>
              <div class="mb"><span>RAM</span>
                <div class="progress" :class="stats.mem_pct > 80 ? 'err' : stats.mem_pct > 60 ? 'warn' : ''">
                  <i :style="'width:' + stats.mem_pct + '%'"></i>
                </div>
                <b class="mono" x-text="stats.mem_pct + '%'"></b>
              </div>
              <div class="mb"><span>Disk</span>
                <div class="progress" :class="stats.hdd_pct > 80 ? 'err' : stats.hdd_pct > 60 ? 'warn' : ''">
                  <i :style="'width:' + stats.hdd_pct + '%'"></i>
                </div>
                <b class="mono" x-text="stats.hdd_pct + '%'"></b>
              </div>
            </div>
            <p class="rc-disk" :class="diskKritis() ? 'is-kritis' : ''" x-show="stats.total_hdd">
              Sisa disk <span class="mono" x-text="fmtBytes(stats.total_hdd - stats.used_hdd) + ' (' + diskSisa().toLocaleString('id-ID') + '%)'"></span>
              <span x-show="diskKritis()"> · di bawah {{ \App\Services\RouterKesehatanService::DISK_KRITIS }}%, record penjualan dan konfigurasi bisa gagal tersimpan</span>
            </p>
          </div>
        </template>

        @if ($isAdmin)
          <template x-if="sehat">
            <dl class="rc-sehat">
              <div><dt>On-login</dt><dd :class="sehat.on_login === 'campuran' ? 't-warn' : ''" x-text="labelOnLogin()"></dd></div>
              <div><dt>Kedaluwarsa</dt><dd :class="nadaPengawas()" x-text="labelPengawas()"></dd></div>
              <div x-show="sehat.script_terpasang"><dt>Script panel</dt><dd :class="sehat.script_terbaru ? '' : 't-warn'" x-text="sehat.script_terbaru ? 'versi terbaru' : 'versi lama, pasang ulang dari Script Router'"></dd></div>
              <div x-show="sehat.scheduler_sisa && sehat.scheduler_sisa.nama.length"><dt>Scheduler sisa</dt><dd class="t-err mono" x-text="sehat.scheduler_sisa ? sehat.scheduler_sisa.nama.join(', ') : ''"></dd></div>
              <div><dt>Penjualan</dt><dd x-text="labelPenjualan()"></dd></div>
            </dl>
          </template>
          <p class="rc-sehat-galat" x-show="sehatGalat" x-text="'Status script tidak terbaca: ' + sehatGalat"></p>
        @endif

        <template x-if="!loading && !online">
          <div style="padding:14px 0;text-align:center;color:var(--text-3)">
            <div style="font-size:13px;color:var(--text-2)">Router tidak dapat dijangkau</div>
            <div style="font-size:11.5px;margin-top:4px" x-text="error"></div>
          </div>
        </template>

        <div class="rc-foot">
          Lihat detail
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </div>
      </a>

      @if ($isAdmin)
        <div class="rc-actions">
          <a href="{{ route('routers.siapkan', $router['id']) }}" class="rc-act" title="Siapkan router" aria-label="Siapkan router {{ $router['name'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/></svg>
          </a>
          <button type="button" class="rc-act" title="Edit router" aria-label="Edit router"
                  data-router-edit
                  data-slug="{{ $router['id'] }}"
                  data-name="{{ $router['name'] }}"
                  data-host="{{ $router['host'] }}"
                  data-port="{{ $router['port'] }}"
                  data-user="{{ $router['user'] }}"
                  data-wan="{{ $router['wan_interface'] }}"
                  data-timeout="{{ $router['timeout'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
          </button>
          <button type="button" class="rc-act rc-act-danger" title="Hapus router" aria-label="Hapus router"
                  data-router-delete
                  data-slug="{{ $router['id'] }}"
                  data-name="{{ $router['name'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
          </button>
        </div>
      @endif
      </div>
    @empty
      <div class="card card-pad" style="grid-column:1/-1;text-align:center;padding:44px 20px">
        <div style="font-weight:600;margin-bottom:4px">Belum ada router terdaftar</div>
        <div style="color:var(--text-3);font-size:13px;margin-bottom:16px">
          Tambahkan MikroTik pertama lewat panel (tidak perlu edit file konfigurasi di server).
        </div>
        @if ($isAdmin)
          <button type="button" class="btn btn-primary" data-router-add>Tambah Router</button>
        @endif
      </div>
    @endforelse
  </section>


@endsection

@push('scripts')
<script>
function routerCard(statsUrl, sehatUrl) {
  return {
    statsUrl,
    loading: true, online: false, stats: {}, error: '', sehat: null, sehatGalat: '',
    async load() {
      try {
        const res = await fetch(statsUrl);
        const data = await res.json();
        this.online = data.online;
        this.stats  = data.stats ?? {};
        this.error  = data.error ?? '';
      } catch (e) { this.online = false; this.error = e.message; }
      finally { this.loading = false; }
      if (sehatUrl && this.online) this.loadSehat();
    },
    async loadSehat() {
      try {
        const res = await fetch(sehatUrl, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || ('HTTP ' + res.status));
        this.sehat = data;
      } catch (e) { this.sehatGalat = e.message; }
    },
    diskSisa() {
      const t = this.stats.total_hdd || 0;
      return t ? Math.round((t - this.stats.used_hdd) / t * 1000) / 10 : 0;
    },
    diskKritis() { return this.stats.total_hdd > 0 && this.diskSisa() < {{ \App\Services\RouterKesehatanService::DISK_KRITIS }}; },
    fmtBytes(b) {
      if (!b) return '0 B';
      const u = ['B', 'KB', 'MB', 'GB'];
      const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
      return (b / Math.pow(1024, i)).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' ' + u[i];
    },
    labelOnLogin() {
      const per = j => this.sehat.profil.filter(p => p.jenis === j).map(p => p.nama).join(', ');
      if (this.sehat.on_login === 'panel') return 'Panel (' + per('panel') + ')';
      if (this.sehat.on_login === 'mikhmon') return 'Script lama (' + per('mikhmon') + ')';
      if (this.sehat.on_login === 'campuran') return 'Campuran: panel ' + (per('panel') || '-') + ', script lama ' + (per('mikhmon') || '-');
      return 'Tidak ada profil jualan';
    },
    labelPengawas() {
      const p = this.sehat.pengawas_panel && !this.sehat.pengawas_panel.disabled;
      const m = this.sehat.pengawas_mikhmon.filter(x => x.aktif).map(x => x.nama);
      if (p && m.length) return 'Dobel: panel dan scheduler lama (' + m.join(', ') + ')';
      if (p) return 'Panel, dicek tiap ' + this.sehat.pengawas_panel.interval;
      if (m.length) return 'Scheduler lama (' + m.join(', ') + ')';
      return 'Tidak ada pengawas aktif';
    },
    nadaPengawas() {
      const p = this.sehat.pengawas_panel && !this.sehat.pengawas_panel.disabled;
      const m = this.sehat.pengawas_mikhmon.some(x => x.aktif);
      return p && m ? 't-warn' : (!p && !m && this.sehat.profil.length ? 't-err' : '');
    },
    labelPenjualan() {
      const n = (this.sehat.record_tersimpan || 0).toLocaleString('id-ID') + ' record tersimpan';
      if (!this.sehat.penjualan_ditarik) return 'belum pernah ditarik · ' + n;
      const menit = Math.max(0, Math.round((Date.now() - new Date(this.sehat.penjualan_ditarik)) / 60000));
      return 'ditarik ' + (menit < 1 ? 'barusan' : menit + ' menit lalu') + ' · ' + n;
    },
    fmtUptime(str) {
      if (!str) return '-';
      const label = { w: 'mg', d: 'hr', h: 'j', m: 'm' };
      const parts = [];
      for (const [, num, unit] of str.matchAll(/(\d+)([wdhms])/g)) if (label[unit]) parts.push(num + label[unit]);
      return parts.join(' ') || '-';
    },
  };
}
</script>
@endpush

@if ($isAdmin)
  @push('overlays')
    @include('routers._form-modal')
  @endpush
@endif

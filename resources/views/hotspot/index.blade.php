@extends('layouts.app')

@section('title', 'Hotspot Aktif')
@section('page-title', 'Hotspot Aktif')
@section('page-class', 'pg-hotspot')

@section('content')

  @php $isAdmin = auth()->user()?->isAdmin(); @endphp

  <header class="page-head">
    <div>
      <h2>Hotspot aktif</h2>
      <p>Sesi, perangkat, dan cookie login dari router. Diperbarui tiap 10 detik selama halaman ini terbuka.</p>
    </div>
    @if ($routers->count() > 1)
      <div class="head-actions">
        <label class="hs-router">
          <span class="t-mute">Router</span>
          <select class="select" aria-label="Pilih router" onchange="location.href = this.value">
            @foreach ($routers as $r)
              <option value="{{ route('hotspot.index', ['router' => $r->slug]) }}" @selected($router && $r->id === $router->id)>{{ $r->name }}</option>
            @endforeach
          </select>
        </label>
      </div>
    @endif
  </header>

  @if (! $router)
    <div class="card card-pad">
      <div class="empty-inner">
        <div class="t-strong">Belum ada router di panel</div>
        <a class="link-strong" href="{{ route('routers.index') }}">Tambah router →</a>
      </div>
    </div>
  @else
    <div class="card" x-data="hotspotMonitor(@js([
          'data'   => route('hotspot.data', $router),
          'kick'   => $isAdmin ? route('hotspot.kick', ['router' => $router, 'id' => '__ID__']) : null,
          'cookie' => $isAdmin ? route('hotspot.cookie', ['router' => $router, 'id' => '__ID__']) : null,
        ]))" x-init="mulai()">

      <div class="tabs card-tabs" role="tablist" aria-label="Jenis data hotspot">
        <template x-for="t in tabs" :key="t.k">
          <button type="button" role="tab" :class="tab === t.k ? 'active' : ''" :aria-selected="tab === t.k ? 'true' : 'false'" @click="tab = t.k; cari = ''">
            <span x-text="t.l"></span> <span class="count" x-text="jumlah(t.k)"></span>
          </button>
        </template>
      </div>

      <div class="toolbar">
        <div class="input-group tb-search">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" class="input" x-model="cari" placeholder="Cari user, IP, atau MAC" aria-label="Cari">
        </div>
        <div class="tb-end hs-state" aria-live="polite" x-cloak>
          <span x-show="keadaan === 'segar'" class="live">Live</span>
          <span x-show="keadaan === 'basi'" class="badge warn" x-text="'Data ' + umurTeks(bagian().umur) + ' lalu'"></span>
          <span x-show="keadaan === 'offline'" class="badge err">Router tidak terjawab</span>
          <span x-show="keadaan === 'poller'" class="badge err">Poller berhenti</span>
          <span x-show="keadaan === 'tunggu'" class="badge">Menyiapkan data</span>
        </div>
      </div>

      <div x-show="memuat" class="hs-skel" aria-hidden="true">
        @for ($i = 0; $i < 5; $i++)
          <div class="skeleton"></div>
        @endfor
      </div>

      <div x-show="!memuat && gagal" class="empty-inner hs-empty" x-cloak>
        <div class="t-strong">Gagal memuat data panel</div>
        <div class="t-mute" x-text="gagal"></div>
        <button type="button" class="btn btn-sm" @click="muat()">Coba lagi</button>
      </div>

      <div x-show="!memuat && !gagal && bagian().data === null" class="empty-inner hs-empty" x-cloak>
        <div class="t-strong">Menunggu data pertama dari router</div>
        <div class="t-mute">Poller mulai membaca router ini begitu halaman dibuka; biasanya kurang dari 15 detik.</div>
      </div>

      <div x-show="!memuat && !gagal && bagian().data !== null" class="tbl-wrap" x-cloak>
        <table class="tbl" x-show="tab === 'active'">
          <thead>
            <tr>
              <th>User</th>
              <th>IP</th>
              <th>Online</th>
              <th class="hs-opt">Login</th>
              <th class="hs-opt">Diam</th>
              <th class="hs-opt ta-r">Unduh / unggah</th>
              @if ($isAdmin) <th class="ta-r"><span class="sr-only">Aksi</span></th> @endif
            </tr>
          </thead>
          <tbody>
            <template x-for="r in tersaring('active')" :key="r['.id']">
              <tr>
                <td><div class="t-strong" x-text="r.user || '-'"></div><div class="mono hs-mac" x-text="r['mac-address'] || '-'"></div></td>
                <td class="mono" x-text="r.address || '-'"></td>
                <td class="mono nowrap" x-text="durasi(r.uptime)"></td>
                <td class="hs-opt" x-text="r['login-by'] || '-'"></td>
                <td class="hs-opt mono t-mute nowrap" x-text="durasi(r['idle-time'])"></td>
                <td class="hs-opt mono ta-r nowrap"><span x-text="bytes(r['bytes-out'])"></span> / <span x-text="bytes(r['bytes-in'])"></span></td>
                @if ($isAdmin)
                  <td>
                    <div class="tbl-actions">
                      <button type="button" class="icon-btn t-err" :disabled="sibuk === r['.id']" @click="putuskan(r)" :title="'Putus sesi ' + (r.user || '')" :aria-label="'Putus sesi ' + (r.user || '')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                      </button>
                    </div>
                  </td>
                @endif
              </tr>
            </template>
          </tbody>
        </table>

        <table class="tbl" x-show="tab === 'host'">
          <thead>
            <tr>
              <th>MAC</th>
              <th>IP</th>
              <th>Status</th>
              <th class="hs-opt">IP tujuan</th>
              <th class="hs-opt">Server</th>
              <th class="hs-opt">Diam</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="r in tersaring('host')" :key="r['.id']">
              <tr>
                <td class="mono" x-text="r['mac-address'] || '-'"></td>
                <td class="mono" x-text="r.address || '-'"></td>
                <td>
                  <span class="badge" :class="r.bypassed === 'true' ? 'info' : (r.authorized === 'true' ? 'ok' : '')"
                        x-text="r.bypassed === 'true' ? 'Bypass' : (r.authorized === 'true' ? 'Sudah login' : 'Belum login')"></span>
                </td>
                <td class="hs-opt mono t-mute" x-text="r['to-address'] || '-'"></td>
                <td class="hs-opt" x-text="r.server || '-'"></td>
                <td class="hs-opt mono t-mute nowrap" x-text="durasi(r['idle-time'])"></td>
              </tr>
            </template>
          </tbody>
        </table>

        <table class="tbl" x-show="tab === 'cookie'">
          <thead>
            <tr>
              <th>User</th>
              <th>Berlaku</th>
              @if ($isAdmin) <th class="ta-r"><span class="sr-only">Aksi</span></th> @endif
            </tr>
          </thead>
          <tbody>
            <template x-for="r in tersaring('cookie')" :key="r['.id']">
              <tr>
                <td><div class="t-strong" x-text="r.user || '-'"></div><div class="mono hs-mac" x-text="r['mac-address'] || '-'"></div></td>
                <td class="mono nowrap" x-text="durasi(r['expires-in'])"></td>
                @if ($isAdmin)
                  <td>
                    <div class="tbl-actions">
                      <button type="button" class="icon-btn t-err" :disabled="sibuk === r['.id']" @click="hapusCookie(r)" :title="'Hapus cookie ' + (r.user || '')" :aria-label="'Hapus cookie ' + (r.user || '')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                      </button>
                    </div>
                  </td>
                @endif
              </tr>
            </template>
          </tbody>
        </table>

        <div class="empty-inner hs-empty" x-show="tersaring(tab).length === 0">
          <div class="t-strong" x-text="cari ? 'Tidak ada yang cocok dengan “' + cari + '”' : kosongTeks()"></div>
        </div>
      </div>

      <div class="tbl-foot hs-foot" x-show="!memuat && !gagal && bagian().umur !== null" x-cloak>
        Data router <span class="mono" x-text="umurTeks(bagian().umur)"></span> lalu
        <span x-show="error" class="t-err" x-text="'· ' + error"></span>
      </div>
    </div>
  @endif

  @include('vouchers._toast')
@endsection

@push('scripts')
<script>
function hotspotMonitor(url) {
  return {
    url, tab: 'active', cari: '', memuat: true, gagal: '', sibuk: null, error: '',
    online: null, pollerHidup: true, d: { active: { data: null, umur: null }, host: { data: null, umur: null }, cookie: { data: null, umur: null } },
    tabs: [{ k: 'active', l: 'Sesi aktif' }, { k: 'host', l: 'Perangkat' }, { k: 'cookie', l: 'Cookie login' }],
    timer: null,

    mulai() {
      this.muat();
      this.timer = setInterval(() => { if (document.visibilityState === 'visible') this.muat(); }, 10000);
      document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') this.muat(); });
    },

    async muat() {
      try {
        const res  = await fetch(this.url.data, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || data.message || ('HTTP ' + res.status));
        this.d = { active: data.active, host: data.host, cookie: data.cookie };
        this.online = data.online;
        this.error = data.online === false ? (data.error || '') : '';
        this.pollerHidup = data.poller_hidup;
        this.gagal = '';
      } catch (e) {
        this.gagal = e.message;
      } finally {
        this.memuat = false;
      }
    },

    bagian() { return this.d[this.tab] || { data: null, umur: null }; },

    get keadaan() {
      if (!this.pollerHidup) return 'poller';
      if (this.online === false) return 'offline';
      if (this.bagian().data === null) return 'tunggu';
      return this.bagian().basi ? 'basi' : 'segar';
    },

    jumlah(k) { const x = this.d[k] && this.d[k].data; return x ? x.length.toLocaleString('id-ID') : '-'; },

    tersaring(k) {
      const rows = (this.d[k] && this.d[k].data) || [];
      const q = this.cari.trim().toLowerCase();
      if (!q) return rows;
      return rows.filter(r => [r.user, r.address, r['mac-address'], r['to-address']].some(v => (v || '').toLowerCase().includes(q)));
    },

    kosongTeks() {
      return { active: 'Tidak ada sesi aktif', host: 'Tidak ada perangkat terhubung', cookie: 'Tidak ada cookie login' }[this.tab];
    },

    async hapus(jenis, r, judul, pesan, aksi) {
      const ok = await window.zeroConfirm({ title: judul, message: pesan, action: aksi, variant: 'warning' });
      if (!ok) return;
      this.sibuk = r['.id'];
      try {
        const res = await fetch(this.url[jenis].replace('__ID__', encodeURIComponent(r['.id'])), {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
          body: JSON.stringify({ user: r.user || '', mac: r['mac-address'] || '' }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || data.message || ('HTTP ' + res.status));
        const bagian = jenis === 'kick' ? 'active' : 'cookie';
        this.d[bagian].data = this.d[bagian].data.filter(x => x['.id'] !== r['.id']);
        window.vgToast((jenis === 'kick' ? 'Sesi ' : 'Cookie ') + (r.user || '') + ' dihapus dari router.', 'ok');
      } catch (e) {
        window.vgToast(e.message, 'err');
        this.muat();
      } finally {
        this.sibuk = null;
      }
    },

    putuskan(r) {
      return this.hapus('kick', r, 'Putus sesi hotspot',
        'Putus sesi "' + (r.user || '-') + '" (' + (r['mac-address'] || '-') + ')? Pelanggan harus memasukkan voucher lagi: di RouterOS 7 login otomatis (mac-cookie) perangkat ini ikut terhapus.',
        'Putus sesi');
    },

    hapusCookie(r) {
      return this.hapus('cookie', r, 'Hapus cookie login',
        'Hapus cookie "' + (r.user || '-') + '" (' + (r['mac-address'] || '-') + ')? Sesi yang sedang berjalan tetap tersambung, tapi login berikutnya harus memasukkan voucher lagi.',
        'Hapus cookie');
    },

    durasi(s) {
      if (!s) return '-';
      const label = { w: 'mg', d: 'hr', h: 'j', m: 'm', s: 'dtk' };
      const bagian = [];
      for (const [, n, u] of String(s).matchAll(/(\d+)([wdhms])/g)) bagian.push(n + label[u]);
      return bagian.slice(0, 2).join(' ') || s;
    },

    bytes(n) {
      n = parseInt(n) || 0;
      const f = (x) => x.toLocaleString('id-ID', { maximumFractionDigits: 1 });
      if (n >= 1073741824) return f(n / 1073741824) + ' GB';
      if (n >= 1048576) return f(n / 1048576) + ' MB';
      if (n >= 1024) return Math.round(n / 1024) + ' KB';
      return n + ' B';
    },

    umurTeks(d) {
      if (d === null || d === undefined) return '-';
      if (d < 60) return d + ' dtk';
      if (d < 3600) return Math.floor(d / 60) + ' mnt';
      return Math.floor(d / 3600) + ' jam';
    },
  };
}
</script>
@endpush

@extends('layouts.app')

@section('title', 'Paket')
@section('page-title', 'Paket')

@section('page-class', 'pg-packages-index')

@section('content')

  @php
    $admin = (bool) auth()->user()?->isAdmin();
  @endphp

  <div x-data="halamanPaket(@js([
      'admin'   => $admin,
      'routers' => $routers->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name])->values(),
      'paket'   => $packages->values(),
      'url'     => [
        'profil'       => route('packages.router-profiles', ['router' => '__R__']),
        'opsi'         => $admin ? route('voucher-profiles.options', ['router' => '__R__']) : null,
        'simpanProfil' => $admin ? route('voucher-profiles.store', ['router' => '__R__']) : null,
        'radius'       => $admin ? route('packages.radius') : null,
        'hapus'        => $admin ? route('packages.destroy', ['package' => '__ID__']) : null,
      ],
    ]))" @keydown.escape.window="modal && !menyimpan && tutup()">

    <header class="page-head">
      <div>
        <h2>Paket</h2>
        <p>Profil hotspot di tiap router. Profil yang juga dipakai pelanggan bulanan menjadi paket FreeRADIUS.</p>
      </div>
      @if ($admin)
        <div class="head-actions">
          <button type="button" class="btn btn-primary" @click="bukaProfil(null)" :disabled="d.status !== 'ok'">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah profil
          </button>
        </div>
      @endif
    </header>

    <div class="card">
      <div class="toolbar">
        <div class="seg pk-router" role="group" aria-label="Pilih router">
          <template x-for="r in routers" :key="r.slug">
            <button type="button" :aria-pressed="slug === r.slug ? 'true' : 'false'" :class="slug === r.slug ? 'active' : ''" @click="pilih(r.slug)" x-text="r.name"></button>
          </template>
        </div>
        <div class="tb-end">
          <span class="t-mute fs-125" x-show="d.status === 'memuat'">Membaca router…</span>
          <span class="t-err fs-125" x-show="d.status === 'gagal'" x-text="d.galat"></span>
          <button type="button" class="btn btn-sm" @click="muat(slug, true)" :disabled="d.status === 'memuat'">Muat ulang</button>
        </div>
      </div>

      <div class="tbl-wrap">
        <table class="tbl pk-tbl">
          <thead>
            <tr>
              <th>Nama</th>
              <th class="num pk-sempit">Shared</th>
              <th>Rate limit</th>
              <th>Saat habis</th>
              <th>Masa</th>
              <th class="num">Harga</th>
              <th class="num pk-sempit">Harga jual</th>
              <th class="pk-sempit">Kunci</th>
              @if ($admin)
                <th class="num">Kartu</th>
                <th class="pk-aksi"><span class="sr-only">Aksi</span></th>
              @endif
            </tr>
          </thead>
          <tbody>
            <template x-for="p in d.profil" :key="p.name">
              <tr>
                <td class="pk-nama">
                  <div class="pk-nama-atas">
                    <span class="mono t-strong" x-text="p.name"></span>
                    <span class="badge no-dot" x-show="p.locked">bawaan</span>
                    <span class="badge warn no-dot" x-show="!p.locked && !p.managed && p.has_script">script lama</span>
                  </div>
                  <template x-for="k in paketDi(p.name)" :key="k.groupname">
                    <div class="pk-radius-tag">
                      <span class="badge info no-dot">RADIUS</span>
                      <span x-text="k.groupname + ' · ' + k.user_count.toLocaleString('id-ID') + ' pelanggan' + (k.is_public ? ' · website' : '')"></span>
                      <span class="t-err" x-show="!k.is_active">nonaktif</span>
                    </div>
                  </template>
                  <div class="pk-ringkas" x-text="ringkas(p)"></div>
                </td>
                <td class="num pk-sempit" x-text="p.shared || '1'"></td>
                <td class="mono pk-rate" :title="p.rate"><span x-text="rateUtama(p.rate)"></span><span class="pk-burst" x-show="p.rate && p.rate.includes(' ')">+ burst</span></td>
                <td class="pk-mode" x-text="labelMode(p)"></td>
                <td class="mono" x-text="p.validity || '-'"></td>
                <td class="num" x-text="p.price ? rp(p.price) : '-'"></td>
                <td class="num pk-sempit" x-text="p.sprice ? rp(p.sprice) : '-'"></td>
                <td class="pk-sempit" x-text="p.lock ? 'ya' : '-'"></td>
                @if ($admin)
                  <td class="num" x-text="pakai(p.name) === null ? '…' : pakai(p.name).toLocaleString('id-ID')"></td>
                  <td class="pk-aksi">
                    <div class="tbl-actions" x-show="!p.locked">
                      <button type="button" class="icon-btn" title="Ubah profil" :aria-label="'Ubah ' + p.name" @click="bukaProfil(p)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </button>
                      <button type="button" class="icon-btn t-err" title="Hapus profil" :aria-label="'Hapus ' + p.name" @click="hapusProfil(p)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                      </button>
                    </div>
                  </td>
                @endif
              </tr>
            </template>
          </tbody>
        </table>
        <div class="pk-kosong" x-show="d.status === 'ok' && !d.profil.length">Router ini belum punya profil hotspot.</div>
        <div class="pk-kosong" x-show="d.status === 'memuat' && !d.profil.length">Membaca profil dari router…</div>
      </div>
    </div>

    <section class="card pk-lepas" x-show="paketLepas.length" x-cloak aria-labelledby="pkLepasJudul">
      <div class="card-head">
        <div>
          <h3 id="pkLepasJudul" x-text="'Paket FreeRADIUS tanpa profil di ' + router.name"></h3>
          <p>Pelanggan paket ini tidak mendapat profil saat login di router ini. Arahkan paket ke profil yang ada, atau buat profilnya.</p>
        </div>
      </div>
      <div class="tbl-wrap">
        <table class="tbl pk-lepas-tbl">
          <thead>
            <tr>
              <th>Paket</th>
              <th>Menunjuk profil</th>
              <th>Pelanggan</th>
              <th>Website</th>
              <th>Status</th>
              @if ($admin)
                <th class="pk-aksi"><span class="sr-only">Aksi</span></th>
              @endif
            </tr>
          </thead>
          <tbody>
            <template x-for="k in paketLepas" :key="k.groupname">
              <tr>
                <td class="pl-paket">
                  <div class="t-strong" x-text="k.nama"></div>
                  <div class="hint mono" x-text="k.groupname"></div>
                </td>
                <td class="pl-profil">
                  <span class="mono" x-text="k.profil || 'belum diatur'"></span>
                  <div class="hint t-warn" x-show="mirip(k)" x-text="'Di router ini namanya ' + mirip(k)"></div>
                </td>
                <td class="pl-user" x-text="k.user_count ? k.user_count.toLocaleString('id-ID') + ' pelanggan' : 'belum ada pelanggan'"></td>
                <td class="pl-web" x-text="k.is_public ? 'tampil di website' : 'tidak di website'"></td>
                <td class="pl-status">
                  <span class="badge warn no-dot" x-show="k.is_legacy">di luar panel</span>
                  <span class="badge" :class="k.is_active ? 'ok' : ''" x-show="!k.is_legacy" x-text="k.is_active ? 'Aktif' : 'Nonaktif'"></span>
                </td>
                @if ($admin)
                  <td class="pk-aksi">
                    <div class="tbl-actions">
                      <button type="button" class="btn btn-sm" @click="bukaRadius(k)">Atur</button>
                      <button type="button" class="icon-btn t-err" title="Hapus paket" :aria-label="'Hapus paket ' + k.groupname" x-show="!k.is_legacy" @click="hapusPaket(k)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                      </button>
                    </div>
                  </td>
                @endif
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    @if ($admin)
      @include('packages._profil-modal')

      <div class="mdl-backdrop" :class="terbuka ? 'is-open' : ''" x-show="modal === 'radius'" x-cloak role="dialog" aria-modal="true" aria-labelledby="pkRadiusJudul"
           @click.self="!menyimpan && tutup()">
        <div class="mdl">
          <div class="mdl-head">
            <div class="grow min0">
              <h3 class="mdl-title" id="pkRadiusJudul" x-text="fr ? 'Paket ' + fr.groupname : 'Paket'"></h3>
              <p class="mdl-sub">Paket FreeRADIUS berlaku di semua router.</p>
            </div>
            <button type="button" class="mdl-close" @click="!menyimpan && tutup()" aria-label="Tutup">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <template x-if="fr">
            <form class="mdl-body" autocomplete="off" @submit.prevent="simpanRadius()">
              <div class="mdl-alert" x-show="galat" x-text="galat" role="alert"></div>
              <div class="field pk-profil-tujuan">
                <label for="pkRProfil">Profil router <span class="req">*</span></label>
                <select id="pkRProfil" class="select" x-model="fr.profil">
                  <template x-for="n in pilihanProfil(fr.profil)" :key="n"><option :value="n" x-text="n + (namaProfil.includes(n) ? '' : ' (tidak ada di ' + router.name + ')')"></option></template>
                </select>
                <small class="mdl-hint">Profil yang diterapkan ke pelanggan saat login. Nama harus sama persis di tiap router.</small>
                <small class="mdl-err" :class="err.r_profil ? 'on' : ''" x-text="err.r_profil"></small>
              </div>
              @include('packages._radius-fields', ['m' => 'fr', 'id' => 'pkF'])
            </form>
          </template>
          <div class="mdl-foot">
            <span class="grow"></span>
            <button type="button" class="btn btn-sm" @click="tutup()" :disabled="menyimpan">Batal</button>
            <button type="button" class="btn btn-sm btn-primary" @click="simpanRadius()" :disabled="menyimpan" x-text="menyimpan ? 'Menyimpan…' : 'Simpan'"></button>
          </div>
        </div>
      </div>

      <form method="POST" x-ref="formAksi" hidden>
        @csrf
        <input type="hidden" name="_method" x-ref="formMetode">
      </form>
    @endif
  </div>

  @include('vouchers._toast')

@endsection

@push('scripts')
<script>
function halamanPaket(cfg) {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const kosongRadius = (nama) => ({
    ada: false, groupname: nama || '', display_name: '', perangkat: 1, speed_label: '', is_active: true,
    is_public: false, validity_days: '', price: '', show_price: false, public_description: '', sort_order: 0,
    description: '', pelanggan: 0, profil: nama || '',
  });
  const dariPaket = (k) => ({
    ada: true, groupname: k.groupname, display_name: k.display_name || '', perangkat: k.perangkat || '', speed_label: k.speed_label || '',
    is_active: !!k.is_active, is_public: !!k.is_public, validity_days: k.validity_days || '', price: k.price ?? '',
    show_price: !!k.show_price, public_description: k.public_description || '', sort_order: k.sort_order_asli ?? 0,
    description: k.description || '', pelanggan: k.user_count || 0, profil: k.profil || '', is_legacy: !!k.is_legacy,
  });

  return {
    admin: cfg.admin, routers: cfg.routers, paket: cfg.paket,
    slug: null, data: {}, opsi: {},
    modal: null, terbuka: false, fp: null, fr: null, err: {}, galat: '', menyimpan: false, jedaTutup: null,

    init() {
      let awal = new URLSearchParams(location.search).get('router');
      try { awal = awal || localStorage.getItem('zn-paket-router'); } catch (e) {}
      const ada = this.routers.some(r => r.slug === awal);
      this.pilih(ada ? awal : (this.routers[0] ? this.routers[0].slug : null));
    },

    get router() { return this.routers.find(r => r.slug === this.slug) || { name: '' }; },
    get d() { return this.data[this.slug] || { status: 'memuat', profil: [] }; },
    get namaProfil() { return this.d.profil.map(p => p.name); },
    get paketLepas() {
      if (this.d.status !== 'ok') return [];
      const n = this.namaProfil;
      return this.paket.filter(k => !k.profil || !n.includes(k.profil));
    },

    pilih(slug) {
      if (!slug) return;
      this.slug = slug;
      try { localStorage.setItem('zn-paket-router', slug); } catch (e) {}
      const u = new URL(location.href); u.searchParams.set('router', slug); history.replaceState(null, '', u);
      if (!this.data[slug] || this.data[slug].status === 'gagal') this.muat(slug);
    },

    async muat(slug, segar) {
      this.data[slug] = { status: 'memuat', profil: (this.data[slug] && this.data[slug].profil) || [] };
      try {
        const res = await fetch(cfg.url.profil.replace('__R__', encodeURIComponent(slug)) + (segar ? '?segar=1' : ''), { headers: { Accept: 'application/json' } });
        const j = await res.json();
        this.data[slug] = j.success ? { status: 'ok', profil: j.profiles } : { status: 'gagal', profil: [], galat: j.error || 'Router tidak terjangkau.' };
      } catch (e) {
        this.data[slug] = { status: 'gagal', profil: [], galat: 'Router tidak terjangkau.' };
      }
      if (this.admin && this.data[slug].status === 'ok') this.muatOpsi(slug);
    },

    async muatOpsi(slug) {
      try {
        const res = await fetch(cfg.url.opsi.replace('__R__', encodeURIComponent(slug)), { headers: { Accept: 'application/json' } });
        const j = await res.json();
        if (j.success) this.opsi[slug] = j;
      } catch (e) {}
    },

    pakai(nama) { const o = this.opsi[this.slug]; return o ? (o.usage[nama] || 0) : null; },
    paketDi(nama) { return this.paket.filter(k => k.profil === nama); },
    mirip(k) { return k.profil ? (this.namaProfil.find(n => n !== k.profil && n.toLowerCase() === k.profil.toLowerCase()) || null) : null; },
    pilihan(jenis, sekarang) {
      const daftar = [...((this.opsi[this.slug] || {})[jenis] || [])];
      if (sekarang && !daftar.includes(sekarang)) daftar.push(sekarang);
      return daftar;
    },
    pilihanProfil(sekarang) {
      const daftar = this.namaProfil.filter(n => n.toLowerCase() !== 'default');
      if (sekarang && !daftar.includes(sekarang)) daftar.unshift(sekarang);
      return daftar;
    },
    rp(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); },
    labelMode(p) { return p.mode === 'off' ? '-' : (p.mode === 'rem' ? 'hapus' : 'nonaktif') + (p.record ? ' + catat' : ''); },
    rateUtama(r) { return r ? r.split(' ')[0] : 'tanpa batas'; },
    ringkas(p) {
      return [(p.shared || '1') + ' perangkat', p.sprice ? 'jual ' + this.rp(p.sprice) : null, p.lock ? 'kunci MAC' : null].filter(Boolean).join(' · ');
    },

    buka(jenis) {
      clearTimeout(this.jedaTutup);
      this.err = {}; this.galat = '';
      this.modal = jenis;
      this.$nextTick(() => requestAnimationFrame(() => { this.terbuka = true; }));
    },
    tutup() {
      this.terbuka = false;
      clearTimeout(this.jedaTutup);
      this.jedaTutup = setTimeout(() => { this.modal = null; this.fp = null; this.fr = null; }, 180);
    },

    bukaProfil(p) {
      const k = p ? this.paketDi(p.name)[0] : null;
      this.fp = {
        asal: p ? p.name : null,
        name: p ? p.name : '', rate_limit: p ? (p.rate || '') : '', shared_users: p ? (p.shared || 1) : 1,
        idle_timeout: p && p.idle && p.idle !== 'none' ? p.idle : '', address_pool: p ? (p.pool || '') : '', parent_queue: p ? (p.parent || '') : '',
        mode: p ? p.mode : 'ntf', validity: p ? (p.validity || '') : '', price: p && p.price ? p.price : '', sprice: p && p.sprice ? p.sprice : '',
        record: p ? !!p.record : true, lock: p ? !!p.lock : false,
        radiusAktif: !!k, radius: k ? dariPaket(k) : kosongRadius(p ? p.name : ''),
      };
      this.buka('profil');
      this.$nextTick(() => { const el = document.getElementById('pkNama'); el && el.focus(); });
    },

    bukaRadius(k) {
      this.fr = dariPaket(k);
      if (!this.fr.profil) this.fr.profil = this.namaProfil[0] || '';
      else if (this.mirip(k)) this.fr.profil = this.mirip(k);
      this.buka('radius');
    },

    tampilGalat(j, status, awalan) {
      if (status === 422 && j.errors) {
        Object.entries(j.errors).forEach(([k, v]) => { this.err[(awalan || '') + k] = v[0]; });
        this.galat = Object.values(j.errors)[0][0];
      } else {
        this.galat = j.error || j.message || 'Gagal menyimpan.';
      }
    },

    async kirim(url, metode, isi) {
      const res = await fetch(url, {
        method: metode,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(isi),
      });
      return { res, j: await res.json().catch(() => ({})) };
    },

    isiRadius(r, profil) {
      const angka = v => v === '' || v === null || v === undefined ? null : parseInt(v, 10);
      return {
        groupname: r.groupname, baru: !r.ada, profil: profil, perangkat: angka(r.perangkat),
        display_name: r.display_name || null, speed_label: r.speed_label || null, description: r.description || null,
        is_active: !!r.is_active, is_public: !!r.is_public, price: angka(r.price), show_price: !!r.show_price,
        validity_days: angka(r.validity_days), public_description: r.public_description || null, sort_order: angka(r.sort_order) || 0,
      };
    },

    async simpanProfil() {
      if (this.menyimpan || !this.fp) return;
      const f = this.fp;
      this.err = {}; this.galat = '';
      const ubah = f.asal !== null;
      const ikutRadius = f.radius.ada || f.radiusAktif;
      if (ikutRadius && !f.radius.ada && !f.radius.groupname) f.radius.groupname = f.name;

      if (ubah && f.name !== f.asal) {
        const lama = this.paketDi(f.asal);
        const ok = await window.zeroConfirm({
          title: 'Ganti nama profil',
          message: 'Profil ' + f.asal + ' di ' + this.router.name + ' diganti nama jadi ' + f.name + '. Kartu yang memakainya ikut pindah.'
            + (lama.length ? ' Paket FreeRADIUS ' + lama.map(k => k.groupname).join(', ') + ' akan ikut diarahkan ke nama baru, jadi di router lain profil ' + f.name + ' juga harus ada.' : ''),
          action: 'Ganti nama', variant: 'warning',
        });
        if (!ok) return;
      }

      this.menyimpan = true;
      try {
        const isi = {
          name: f.name, rate_limit: f.rate_limit, shared_users: parseInt(f.shared_users || '1', 10), idle_timeout: f.idle_timeout,
          address_pool: f.address_pool, parent_queue: f.parent_queue, mode: f.mode, validity: f.validity,
          price: parseInt(f.price || '0', 10), sprice: parseInt(f.sprice || '0', 10), record: !!f.record, lock: !!f.lock,
        };
        if (ubah) isi.original_name = f.asal;
        const a = await this.kirim(cfg.url.simpanProfil.replace('__R__', encodeURIComponent(this.slug)), ubah ? 'PUT' : 'POST', isi);
        if (!a.res.ok || !a.j.success) { this.tampilGalat(a.j, a.res.status); return; }
        let pesan = a.j.message;

        if (ubah && f.name !== f.asal) {
          for (const k of this.paketDi(f.asal).filter(x => x.groupname !== f.radius.groupname)) {
            const c = await this.kirim(cfg.url.radius, 'POST', this.isiRadius(dariPaket(k), f.name));
            if (c.res.ok && c.j.success) this.paket = c.j.paket;
            else pesan += ' Paket ' + k.groupname + ' belum diarahkan ulang, atur manual.';
          }
        }

        if (ikutRadius) {
          const b = await this.kirim(cfg.url.radius, 'POST', this.isiRadius(f.radius, f.name));
          if (!b.res.ok || !b.j.success) {
            this.tampilGalat(b.j, b.res.status, 'r_');
            this.galat = 'Profil tersimpan di router, tapi paket FreeRADIUS belum: ' + this.galat;
            f.asal = f.name;
            this.muat(this.slug, true);
            return;
          }
          this.paket = b.j.paket;
          pesan += ' ' + b.j.message;
        }

        window.vgToast(pesan, 'ok');
        this.tutup();
        this.muat(this.slug, true);
      } catch (e) {
        this.galat = 'Gagal menghubungi server: ' + e.message;
      } finally {
        this.menyimpan = false;
      }
    },

    async simpanRadius() {
      if (this.menyimpan || !this.fr) return;
      this.err = {}; this.galat = '';
      this.menyimpan = true;
      try {
        const b = await this.kirim(cfg.url.radius, 'POST', this.isiRadius(this.fr, this.fr.profil));
        if (!b.res.ok || !b.j.success) { this.tampilGalat(b.j, b.res.status, 'r_'); return; }
        this.paket = b.j.paket;
        window.vgToast(b.j.message, 'ok');
        this.tutup();
      } catch (e) {
        this.galat = 'Gagal menghubungi server: ' + e.message;
      } finally {
        this.menyimpan = false;
      }
    },

    async hapusProfil(p) {
      const n = this.pakai(p.name) || 0;
      const k = this.paketDi(p.name);
      const ok = await window.zeroConfirm({
        title: 'Hapus profil',
        message: 'Hapus profil ' + p.name + ' dari ' + this.router.name + '?'
          + (n > 0 ? ' Masih dipakai ' + n.toLocaleString('id-ID') + ' kartu, router akan menolak sampai kartunya dipindah atau dihapus.' : '')
          + (k.length ? ' Paket FreeRADIUS ' + k.map(x => x.groupname).join(', ') + ' menunjuk profil ini; pelanggannya tidak mendapat profil di router ini.' : ''),
        action: 'Hapus', variant: 'danger',
      });
      if (!ok) return;
      try {
        const a = await this.kirim(cfg.url.simpanProfil.replace('__R__', encodeURIComponent(this.slug)), 'DELETE', { name: p.name });
        window.vgToast(a.j.message || a.j.error || 'Gagal menghapus.', a.j.success ? 'ok' : 'err');
        if (a.j.success) this.muat(this.slug, true);
      } catch (e) {
        window.vgToast('Gagal menghubungi server: ' + e.message, 'err');
      }
    },

    async hapusPaket(k) {
      const ok = await window.zeroConfirm({
        title: 'Hapus paket FreeRADIUS',
        message: 'Hapus paket ' + k.groupname + '?' + (k.user_count ? ' Masih dipakai ' + k.user_count + ' pelanggan.' : '')
          + (k.is_public ? ' Paket ini juga hilang dari website.' : '') + ' Profil di router tidak ikut terhapus.',
        action: 'Hapus', variant: 'danger',
      });
      if (!ok) return;
      this.$refs.formAksi.action = cfg.url.hapus.replace('__ID__', k.id);
      this.$refs.formMetode.value = 'DELETE';
      this.$refs.formAksi.submit();
    },
  };
}
</script>
@endpush

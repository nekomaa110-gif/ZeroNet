@extends('layouts.app')

@section('title', 'Template Cetak')
@section('page-title', 'Template Cetak')
@section('page-class', 'pg-template-cetak')

@section('content')

  <header class="page-head">
    <div>
      <h2>Template cetak voucher</h2>
      <p>HTML kartu voucher dengan placeholder seperti <code>@{{kode}}</code>. Kode PHP di dalamnya tidak dijalankan.</p>
    </div>
  </header>

  <div x-data="templateCetak(@js([
      'templates' => $templates->map(fn ($t) => $t->only(['id', 'name', 'per_row', 'html', 'bawaan']))->values(),
      'routers'   => $routers->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'tpl' => $r->template_cetak ?: 'v4'])->values(),
      'panel'     => collect($pilihan)->filter(fn ($label, $key) => ! preg_match('/^t\d+$/', $key))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
      'url' => [
        'simpan'    => route('voucher-templates.store'),
        'ubah'      => route('voucher-templates.update', ['template' => '__ID__']),
        'hapus'     => route('voucher-templates.destroy', ['template' => '__ID__']),
        'pulihkan'  => route('voucher-templates.pulihkan', ['template' => '__ID__']),
        'pratinjau' => route('voucher-templates.pratinjau'),
        'default'   => route('voucher-templates.default', ['router' => '__R__']),
      ],
    ]))">

    <section class="card tc-default" aria-labelledby="tcDefaultJudul">
      <div class="tc-default-judul">
        <h3 id="tcDefaultJudul">Default per router</h3>
        <p>Dipakai saat cetak kalau tidak memilih template lain.</p>
      </div>
      <div class="tc-default-isi">
        <template x-for="r in routers" :key="r.slug">
          <label class="tc-default-baris">
            <span x-text="r.name"></span>
            <select class="select" @change="aturDefault(r, $event.target)">
              <template x-for="o in opsiDefault" :key="o.key">
                <option :value="o.key" :selected="o.key === r.tpl" x-text="o.label"></option>
              </template>
            </select>
          </label>
        </template>
      </div>
    </section>

    <section class="card tc-kerja" aria-label="Editor template">
      <div class="tc-pilih">
        <template x-for="t in urut" :key="t.id">
          <button type="button" class="tc-pilih-item" :class="aktif && aktif.id === t.id ? 'is-aktif' : ''" :aria-pressed="aktif && aktif.id === t.id ? 'true' : 'false'" @click="pilih(t)">
            <span class="tc-pilih-nama" x-text="t.name"></span>
            <span class="tc-pilih-meta">
              <span x-text="(t.per_row > 0 ? t.per_row + ' per baris' : 'Mengalir') + (t.bawaan ? ' · bawaan' : '')"></span>
              <template x-for="nama in dipakai(t)" :key="nama">
                <span class="badge brand no-dot" x-text="'Default ' + nama"></span>
              </template>
            </span>
          </button>
        </template>
        <button type="button" class="tc-pilih-baru" :class="f && !f.id ? 'is-aktif' : ''" @click="baru()">+ Template baru</button>
      </div>

      <p class="t-mute tc-kosong" x-show="!templates.length && !f">Belum ada template. Susunan bawaan 1/4/8/10 per baris tetap bisa dipakai lewat default router.</p>

      <div x-show="f" x-cloak>
        <template x-if="f">
          <div>
            <div class="tc-kepala">
              <div class="field tc-f-nama">
                <label for="tcNama">Nama</label>
                <input id="tcNama" type="text" class="input" x-model="f.name" maxlength="60">
              </div>
              <div class="field tc-f-susunan">
                <label for="tcBaris">Susunan</label>
                <select id="tcBaris" class="select" x-model.number="f.per_row" @change="jadwalPratinjau()">
                  <option value="0">Mengalir</option>
                  @foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $n)
                    <option value="{{ $n }}">{{ $n }} per baris</option>
                  @endforeach
                </select>
              </div>
              <div class="tc-tindakan">
                <span class="tc-status" :class="berubah || !f.id ? 'is-kotor' : ''" aria-live="polite" x-text="berubah || !f.id ? 'Belum disimpan' : 'Tersimpan'"></span>
                <button type="button" class="btn btn-ghost t-err" x-show="f.id" @click="hapus()">Hapus</button>
                <button type="button" class="btn" x-show="f.id" @click="duplikat()">Duplikat</button>
                <button type="button" class="btn" x-show="f.bawaan" @click="pulihkan()">Kembalikan ke asli</button>
                <button type="button" class="btn btn-primary" :disabled="menyimpan || (f.id && !berubah)" @click="simpan()" x-text="menyimpan ? 'Menyimpan…' : 'Simpan'"></button>
              </div>
            </div>

            <div class="tc-pesan" x-show="galat || cek.dibuang.length || cek.asing.length">
              <div class="mdl-alert" x-show="galat" x-text="galat" role="alert"></div>
              <div class="tc-peringatan" x-show="cek.dibuang.length || cek.asing.length">
                <div x-show="cek.dibuang.length">Dibuang saat cetak: <span class="mono" x-text="cek.dibuang.join(', ')"></span></div>
                <div x-show="cek.asing.length">Placeholder tidak dikenal (tercetak kosong): <span class="mono" x-text="cek.asing.map(n => '@{{' + n + '}}').join(', ')"></span></div>
              </div>
            </div>

            <div class="tc-badan">
              <div class="tc-kolom">
                <div class="tc-kolom-kepala">
                  <label for="tcHtml">HTML kartu</label>
                  <button type="button" class="btn btn-sm" :aria-expanded="bantuan ? 'true' : 'false'" aria-controls="tcBantuan" @click="bantuan = !bantuan" x-text="bantuan ? 'Tutup placeholder' : 'Placeholder'"></button>
                </div>
                <div id="tcBantuan" class="tc-bantuan" x-show="bantuan">
                  <p>Klik untuk menyisipkan di posisi kursor. Blok <code>@{{#vc}}...@{{/vc}}</code> hanya muncul untuk voucher satu kode, <code>@{{#up}}...@{{/up}}</code> untuk username + password, <code>@{{#kuota}}...@{{/kuota}}</code> hanya kalau kuotanya ada. Script, iframe, form, dan alamat luar dibuang saat cetak.</p>
                  <div class="tc-var">
                    @foreach ($variabel as $nama => $v)
                      <button type="button" class="tc-var-item" @click="sisipkan(@js($nama))">
                        <code>{{ $v['tanda'] }}</code>
                        <span>{{ $v['arti'] }}</span>
                      </button>
                    @endforeach
                  </div>
                </div>
                <textarea id="tcHtml" class="input mono tc-html" x-model="f.html" x-ref="html" @input="jadwalPratinjau()" spellcheck="false"></textarea>
              </div>

              <div class="tc-kolom">
                <div class="tc-kolom-kepala">
                  <span class="tc-kolom-judul">Pratinjau</span>
                  <span class="t-mute">data contoh, ukuran kartu asli</span>
                </div>
                <iframe class="tc-pratinjau" sandbox="" title="Pratinjau template" :srcdoc="pratinjau"></iframe>
              </div>
            </div>
          </div>
        </template>
      </div>
    </section>
  </div>

  @include('vouchers._toast')

@endsection

@push('scripts')
<script>
function templateCetak(cfg) {
  return {
    templates: cfg.templates, routers: cfg.routers, aktif: null, f: null, asal: '', pratinjau: '', cek: { dibuang: [], asing: [] },
    galat: '', menyimpan: false, jeda: null, bantuan: false, lepas: false,

    init() {
      if (this.templates.length) this.pilih(this.urut[0], true);
      const akar = this.$el;
      document.addEventListener('click', (e) => {
        if (this.lepas || !this.berubah || e.defaultPrevented) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || akar.contains(a) || a.hasAttribute('download') || (a.target && a.target !== '_self')) return;
        let url;
        try { url = new URL(a.href, location.href); } catch (_) { return; }
        if (!/^https?:$/.test(url.protocol) || (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search)) return;
        e.preventDefault(); e.stopImmediatePropagation();
        this.bolehPindah().then(ok => { if (ok) { this.lepas = true; a.click(); } });
      }, true);
      document.addEventListener('submit', (e) => {
        const form = e.target;
        if (this.lepas || !this.berubah || e.defaultPrevented || !(form instanceof HTMLFormElement) || akar.contains(form)) return;
        e.preventDefault(); e.stopImmediatePropagation();
        const tombol = e.submitter;
        this.bolehPindah().then(ok => { if (ok) { this.lepas = true; form.requestSubmit ? form.requestSubmit(tombol || undefined) : form.submit(); } });
      }, true);
    },

    get urut() { return [...this.templates].sort((a, b) => a.name.localeCompare(b.name, 'id')); },
    get opsiDefault() { return [...cfg.panel, ...this.urut.map(t => ({ key: 't' + t.id, label: t.name }))]; },
    get berubah() { return !!this.f && this.jejak(this.f) !== this.asal; },
    jejak(t) { return JSON.stringify([t.name, Number(t.per_row), t.html]); },
    dipakai(t) { return this.routers.filter(r => r.tpl === 't' + t.id).map(r => r.name); },
    tanya(o) { return window.zeroConfirm ? window.zeroConfirm(o) : Promise.resolve(false); },
    async bolehPindah() {
      if (!this.berubah) return true;
      return this.tanya({ title: 'Perubahan belum disimpan', message: 'Perubahan di template ' + this.f.name + ' akan hilang kalau Anda pindah sekarang.', action: 'Buang perubahan', cancel: 'Tetap di sini', variant: 'warning' });
    },

    async minta(url, metode, isi) {
      const res = await fetch(url, {
        method: metode,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: isi ? JSON.stringify(isi) : undefined,
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.success === false) {
        throw new Error(data.errors ? Object.values(data.errors)[0][0] : (data.error || data.message || ('HTTP ' + res.status)));
      }
      return data;
    },

    buka(f) { this.f = f; this.asal = this.jejak(f); this.galat = ''; this.muatPratinjau(); },
    async pilih(t, paksa) {
      if (!paksa && this.aktif && this.aktif.id === t.id) return;
      if (!paksa && !(await this.bolehPindah())) return;
      this.aktif = t; this.buka(JSON.parse(JSON.stringify(t)));
    },
    async baru() {
      if (!(await this.bolehPindah())) return;
      this.aktif = null;
      this.buka({ id: null, name: 'Template baru', per_row: 4, bawaan: null, html: '<div style="border:1px dashed #555;padding:6px;text-align:center;font-family:Arial">\n  <div style="font-weight:bold">@{{brand}}</div>\n  @{{#vc}}<div style="font-size:18px;font-weight:bold">@{{kode}}</div>@{{/vc}}\n  @{{#up}}<div>User <b>@{{kode}}</b> · Pass <b>@{{password}}</b></div>@{{/up}}\n  <div>@{{batas_waktu}} · @{{harga}}</div>\n  <div style="font-size:10px">Masa berlaku @{{masa}} · login @{{hotspot}}</div>\n</div>' });
    },
    duplikat() {
      const s = this.f;
      this.aktif = null;
      this.buka({ id: null, name: (s.name + ' (salinan)').slice(0, 60), per_row: s.per_row, bawaan: null, html: s.html });
    },

    sisipkan(nama) {
      const el = this.$refs.html, teks = '{' + '{' + nama + '}' + '}';
      const a = el.selectionStart ?? this.f.html.length, b = el.selectionEnd ?? a;
      this.f.html = this.f.html.slice(0, a) + teks + this.f.html.slice(b);
      this.$nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = a + teks.length; });
      this.jadwalPratinjau();
    },

    jadwalPratinjau() { clearTimeout(this.jeda); this.jeda = setTimeout(() => this.muatPratinjau(), 500); },
    async muatPratinjau() {
      if (!this.f || !this.f.html) { this.pratinjau = ''; return; }
      try {
        const data = await this.minta(cfg.url.pratinjau, 'POST', { html: this.f.html, per_row: this.f.per_row });
        this.pratinjau = data.html;
        this.cek = { dibuang: data.dibuang, asing: data.asing };
      } catch (e) { this.galat = e.message; }
    },

    ganti(t) {
      const i = this.templates.findIndex(x => x.id === t.id);
      if (i >= 0) this.templates.splice(i, 1, t); else this.templates.push(t);
      this.pilih(t, true);
    },

    async simpan() {
      this.menyimpan = true; this.galat = '';
      try {
        const isi = { name: this.f.name, per_row: this.f.per_row, html: this.f.html };
        const data = this.f.id
          ? await this.minta(cfg.url.ubah.replace('__ID__', this.f.id), 'PUT', isi)
          : await this.minta(cfg.url.simpan, 'POST', isi);
        this.ganti(data.template);
        window.vgToast ? window.vgToast(data.message, 'ok') : null;
      } catch (e) { this.galat = e.message; }
      finally { this.menyimpan = false; }
    },

    async pulihkan() {
      if (!(await this.tanya({ title: 'Kembalikan ke versi bawaan?', message: 'Isi template ' + this.f.name + ' diganti dengan versi bawaan panel. Perubahan yang pernah disimpan di template ini hilang.', action: 'Kembalikan', variant: 'warning' }))) return;
      try {
        const data = await this.minta(cfg.url.pulihkan.replace('__ID__', this.f.id), 'POST');
        this.ganti(data.template);
        window.vgToast ? window.vgToast(data.message, 'ok') : null;
      } catch (e) { this.galat = e.message; }
    },

    async hapus() {
      if (!(await this.tanya({ title: 'Hapus template?', message: 'Template ' + this.f.name + ' dihapus permanen.', action: 'Hapus', variant: 'danger' }))) return;
      try {
        const id = this.f.id, data = await this.minta(cfg.url.hapus.replace('__ID__', id), 'DELETE');
        this.templates = this.templates.filter(t => t.id !== id);
        this.f = null; this.aktif = null;
        if (this.templates.length) this.pilih(this.urut[0], true);
        window.vgToast ? window.vgToast(data.message, 'ok') : null;
      } catch (e) { this.galat = e.message; }
    },

    async aturDefault(r, el) {
      const lama = r.tpl, nilai = el.value;
      r.tpl = nilai;
      try {
        const data = await this.minta(cfg.url.default.replace('__R__', encodeURIComponent(r.slug)), 'PUT', { template: nilai });
        window.vgToast ? window.vgToast(data.message, 'ok') : null;
      } catch (e) {
        r.tpl = lama; el.value = lama;
        window.vgToast ? window.vgToast(e.message, 'err') : null;
      }
    },
  };
}
</script>
@endpush

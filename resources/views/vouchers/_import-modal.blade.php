<div x-data="imporMikhmon(@js(['pratinjau' => route('vouchers.import.preview', ['router' => '__R__']), 'impor' => route('vouchers.import', ['router' => '__R__'])]))"
     @impor-mikhmon.window="buka()">
  <div class="mdl-backdrop" :class="terbuka ? 'is-open' : ''" x-show="tampil" x-cloak role="dialog" aria-modal="true" aria-labelledby="imTitle"
       @click.self="tutup()" @keydown.escape.window="tampil && tutup()">
    <div class="mdl mdl-lg">
      <div class="mdl-head">
        <div class="mdl-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </div>
        <div class="grow min0">
          <h3 class="mdl-title" id="imTitle">Impor voucher dari router</h3>
          <p class="mdl-sub">Menyalin voucher yang sudah ada di router ke panel. Router tidak diubah; impor ulang tidak menggandakan.</p>
        </div>
        <button type="button" class="mdl-close" @click="tutup()" aria-label="Tutup">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="mdl-body">
        <div class="im-pilih">
          <div class="field grow">
            <label for="imRouter">Router</label>
            <select id="imRouter" class="select" x-model="router" @change="hasil = null; galat = ''">
              <option value="">(pilih router)</option>
              @foreach ($routers as $r)
                <option value="{{ $r->slug }}">{{ $r->name }}</option>
              @endforeach
            </select>
          </div>
          <button type="button" class="btn" :disabled="!router || memuat" @click="pratinjau()">
            <span x-show="!memuat">Pratinjau</span>
            <span x-show="memuat">Membaca router…</span>
          </button>
        </div>

        <div class="mdl-alert" x-show="galat" x-text="galat" role="alert"></div>

        <p class="mdl-hint" x-show="memuat">Membaca daftar user dan record penjualan. Router besar bisa butuh 10 sampai 20 detik.</p>

        <template x-if="hasil">
          <div class="im-hasil">
            <dl class="im-angka">
              <div><dt>Belum dipakai</dt><dd class="tnum" x-text="angka(hasil.ringkasan.belum_dipakai)"></dd></div>
              <div><dt>Sudah dipakai</dt><dd class="tnum" x-text="angka(hasil.ringkasan.terpakai)"></dd></div>
              <div><dt>Sudah ada di panel</dt><dd class="tnum" x-text="angka(hasil.ringkasan.sudah_ada)"></dd></div>
              <div><dt>Dilewati</dt><dd class="tnum" x-text="angka(hasil.ringkasan.bukan_voucher + hasil.ringkasan.nama_kembar)"></dd></div>
            </dl>
            <p class="mdl-hint">
              <span x-text="angka(hasil.ringkasan.user_router)"></span> user di router.
              <template x-if="hasil.ringkasan.terpakai_tanpa_batch > 0">
                <span><span x-text="angka(hasil.ringkasan.terpakai_tanpa_batch)"></span> voucher terpakai tidak punya asal batch dan masuk ke batch “tanpa-batch”.</span>
              </template>
              <template x-if="hasil.ringkasan.nama_kembar > 0">
                <span><span x-text="angka(hasil.ringkasan.nama_kembar)"></span> nama kembar dilewati.</span>
              </template>
            </p>
            <div class="tbl-wrap im-batch" x-show="hasil.batch.length">
              <table class="tbl">
                <thead><tr><th>Batch</th><th>Profil</th><th class="ta-r">Kartu</th></tr></thead>
                <tbody>
                  <template x-for="b in hasil.batch.slice(0, 8)" :key="b.kode">
                    <tr><td class="mono" x-text="b.comment"></td><td x-text="b.profile"></td><td class="mono ta-r" x-text="angka(b.jumlah)"></td></tr>
                  </template>
                </tbody>
              </table>
            </div>
            <p class="mdl-hint" x-show="hasil.batch.length > 8" x-text="'dan ' + (hasil.batch.length - 8) + ' batch lain'"></p>
          </div>
        </template>
      </div>

      <div class="mdl-foot">
        <button type="button" class="btn btn-ghost" @click="tutup()">Batal</button>
        <span class="spacer"></span>
        <button type="button" class="btn btn-primary" :disabled="!bisaImpor() || mengimpor" @click="impor()"
                x-text="mengimpor ? 'Mengirim…' : (hasil ? 'Impor ' + angka(hasil.ringkasan.belum_dipakai + hasil.ringkasan.terpakai) + ' voucher' : 'Impor')"></button>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
function imporMikhmon(url) {
  return {
    url, tampil: false, terbuka: false, router: '', memuat: false, mengimpor: false, galat: '', hasil: null,

    init() {
      const awal = new URLSearchParams(location.search).get('impor');
      const pilihan = document.getElementById('imRouter');
      if (awal && pilihan && [...pilihan.options].some(o => o.value === awal)) {
        this.router = awal;
        this.buka();
      }
    },

    buka() { this.tampil = true; requestAnimationFrame(() => { this.terbuka = true; }); },
    tutup() { this.terbuka = false; setTimeout(() => { this.tampil = false; this.hasil = null; this.galat = ''; }, 180); },

    angka(n) { return (n || 0).toLocaleString('id-ID'); },
    bisaImpor() { return this.hasil && (this.hasil.ringkasan.belum_dipakai + this.hasil.ringkasan.terpakai) > 0; },

    async minta(alamat, opsi) {
      const res  = await fetch(alamat.replace('__R__', encodeURIComponent(this.router)), Object.assign({ headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }, opsi || {}));
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.success === false) throw new Error(data.error || data.message || ('HTTP ' + res.status));
      return data;
    },

    async pratinjau() {
      this.memuat = true; this.galat = ''; this.hasil = null;
      try { this.hasil = await this.minta(this.url.pratinjau); }
      catch (e) { this.galat = e.message; }
      finally { this.memuat = false; }
    },

    async impor() {
      this.mengimpor = true; this.galat = '';
      try {
        const data = await this.minta(this.url.impor, { method: 'POST' });
        window.vgToast(data.message, 'ok');
        this.tutup();
      } catch (e) { this.galat = e.message; }
      finally { this.mengimpor = false; }
    },
  };
}
</script>
@endpush

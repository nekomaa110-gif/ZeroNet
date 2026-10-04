<div x-data="voucherCepat(@js(['opsi' => route('vouchers.router-options', ['router' => '__R__']), 'simpan' => route('vouchers.cepat')]))"
     @voucher-cepat.window="buka()">
  <div class="mdl-backdrop" :class="terbuka ? 'is-open' : ''" x-show="tampil" x-cloak role="dialog" aria-modal="true" aria-labelledby="vcTitle"
       @click.self="tutup()" @keydown.escape.window="tampil && tutup()">
    <div class="mdl">
      <div class="mdl-head">
        <div class="mdl-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div class="grow min0">
          <h3 class="mdl-title" id="vcTitle">Tambah 1 voucher</h3>
          <p class="mdl-sub">Nama dan password diketik sendiri. Tercatat sebagai batch berisi 1.</p>
        </div>
        <button type="button" class="mdl-close" @click="tutup()" aria-label="Tutup">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <form class="mdl-body" @submit.prevent="simpan()" autocomplete="off">
        <div class="mdl-alert" x-show="galat" x-text="galat" role="alert"></div>

        <div class="mdl-grid">
          <div class="field">
            <label for="vcRouter">Router <span class="req">*</span></label>
            <select id="vcRouter" class="select" x-model="f.router" @change="muatOpsi()" required>
              <option value="">(pilih router)</option>
              @foreach ($routers as $r)
                <option value="{{ $r->slug }}">{{ $r->name }}</option>
              @endforeach
            </select>
          </div>

          <div class="field">
            <label for="vcProfil">Profil <span class="req">*</span></label>
            <select id="vcProfil" class="select" x-model="f.profile" :disabled="!profil.length" required>
              <template x-for="p in profil" :key="p.name">
                <option :value="p.name" x-text="p.name + (p.validity ? ' · ' + p.validity + (p.price ? ' · Rp ' + p.price.toLocaleString('id-ID') : '') : '')"></option>
              </template>
            </select>
            <small class="mdl-hint" x-show="memuat">Membaca profil dari router…</small>
          </div>

          <div class="field">
            <label for="vcUser">Nama voucher <span class="req">*</span></label>
            <input id="vcUser" type="text" class="input mono" x-model.trim="f.username" maxlength="64" required pattern="[A-Za-z0-9._@\-]+">
          </div>

          <div class="field">
            <label for="vcPass">Password</label>
            <input id="vcPass" type="text" class="input mono" x-model.trim="f.password" maxlength="64" pattern="[A-Za-z0-9._@\-]*" placeholder="sama dengan nama">
          </div>

          <div class="field vc-penuh">
            <label for="vcComment">Catatan</label>
            <input id="vcComment" type="text" class="input" x-model.trim="f.comment" maxlength="40" placeholder="mis. ganti-voucher">
            <small class="mdl-hint" x-text="'Comment di router: ' + ((!f.password || f.password === f.username) ? 'vc-' : 'up-') + f.comment"></small>
          </div>

          <div class="field">
            <label for="vcUptime">Jatah jam</label>
            <input id="vcUptime" type="text" class="input mono" x-model.trim="f.limit_uptime" maxlength="20" placeholder="tanpa batas">
          </div>

          <div class="field">
            <label for="vcMb">Kuota (MB)</label>
            <input id="vcMb" type="number" class="input mono" x-model="f.limit_mb" min="0" placeholder="tanpa batas">
          </div>

          <div class="field vc-penuh" x-show="server.length > 1">
            <label for="vcServer">Server hotspot</label>
            <select id="vcServer" class="select" x-model="f.server">
              <option value="">semua</option>
              <template x-for="s in server" :key="s"><option :value="s" x-text="s"></option></template>
            </select>
          </div>
        </div>
      </form>

      <div class="mdl-foot">
        <button type="button" class="btn btn-ghost" @click="tutup()">Batal</button>
        <span class="spacer"></span>
        <button type="button" class="btn btn-primary" :disabled="menyimpan || !f.router || !f.profile || !f.username" @click="simpan()"
                x-text="menyimpan ? 'Menulis ke router…' : 'Tambah'"></button>
      </div>
    </div>
  </div>
</div>

@push('scripts')
<script>
function voucherCepat(url) {
  const kosong = () => ({ router: '', profile: '', username: '', password: '', comment: '', limit_uptime: '', limit_mb: '', server: '' });
  return {
    url, tampil: false, terbuka: false, memuat: false, menyimpan: false, galat: '', profil: [], server: [], f: kosong(),

    buka() { this.tampil = true; requestAnimationFrame(() => { this.terbuka = true; }); },
    tutup() { this.terbuka = false; setTimeout(() => { this.tampil = false; this.galat = ''; this.f = kosong(); this.profil = []; this.server = []; }, 180); },

    async minta(alamat, opsi) {
      const res  = await fetch(alamat, Object.assign({ headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }, opsi || {}));
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.success === false) {
        const pertama = data.errors ? Object.values(data.errors)[0][0] : null;
        throw new Error(pertama || data.error || data.message || ('HTTP ' + res.status));
      }
      return data;
    },

    async muatOpsi() {
      this.profil = []; this.server = []; this.f.profile = ''; this.galat = '';
      if (!this.f.router) return;
      this.memuat = true;
      try {
        const data = await this.minta(this.url.opsi.replace('__R__', encodeURIComponent(this.f.router)));
        this.profil = data.profiles;
        this.server = data.servers || [];
        const jual = this.profil.find(p => p.voucher);
        this.f.profile = (jual || this.profil[0] || {}).name || '';
      } catch (e) { this.galat = e.message; }
      finally { this.memuat = false; }
    },

    async simpan() {
      this.menyimpan = true; this.galat = '';
      try {
        const data = await this.minta(this.url.simpan, { method: 'POST', body: JSON.stringify(this.f) });
        window.vgToast(data.message, 'ok');
        window.location = data.redirect;
      } catch (e) { this.galat = e.message; }
      finally { this.menyimpan = false; }
    },
  };
}
</script>
@endpush

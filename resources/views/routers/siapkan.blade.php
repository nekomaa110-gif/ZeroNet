@extends('layouts.app')

@section('title', 'Siapkan ' . $router->name)
@section('page-title', 'Manajemen Router')
@section('page-class', 'pg-routers-siapkan')

@section('content')

<div class="sp-wadah" x-data="siapkanRouter(@js([
      'periksa' => route('routers.siapkan.periksa', $router),
      'akun'    => route('routers.akun-panel', $router),
      'script'  => route('voucher-scripts.install', $router),
      'namaAkun' => $namaAkun,
    ]))">

  <header class="page-head">
    <div class="min0">
      <h2>Siapkan {{ $router->name }}</h2>
      <p>
        <span class="mono">{{ $router->host }}:{{ $router->port }}</span>
        <span x-show="d" x-text="' · ' + jumlahSelesai() + ' dari 3 langkah selesai'"></span>
      </p>
    </div>
    <div class="head-actions">
      <a href="{{ route('routers.index') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Kembali
      </a>
      <button type="button" class="btn" @click="periksa()" :disabled="memuat || sibuk">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
        <span x-text="memuat ? 'Memeriksa…' : 'Periksa ulang'"></span>
      </button>
    </div>
  </header>

  <ol class="sp-langkah">

    <li class="card sp-step" :data-status="status('koneksi')">
      <div class="sp-kepala">
        <span class="sp-no" aria-hidden="true"><b>1</b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div class="min0">
          <h3>Koneksi</h3>
          <p>Panel masuk ke router lewat API dan membaca identitas serta versinya.</p>
        </div>
        <span class="badge push-end" :class="nadaBadge('koneksi')" x-text="labelStatus('koneksi')"></span>
      </div>

      <div class="sp-isi" x-show="d">
        <dl class="sp-fakta">
          <div><dt>Identitas</dt><dd x-text="d ? d.koneksi.identitas : ''"></dd></div>
          <div><dt>Perangkat</dt><dd x-text="d ? d.koneksi.board : ''"></dd></div>
          <div><dt>RouterOS</dt><dd><span class="mono" x-text="d ? d.koneksi.versi : ''"></span><span class="t-mute" x-show="d && d.koneksi.tanggal_iso"> · tanggal ISO</span></dd></div>
          <div><dt>Server hotspot</dt><dd :class="d && !d.koneksi.server.length ? 't-warn' : ''" x-text="d ? (d.koneksi.server.join(', ') || 'belum ada') : ''"></dd></div>
        </dl>
        <p class="sp-catatan t-warn" x-show="d && !d.koneksi.server.length">Voucher baru bisa dipakai login setelah server hotspot dibuat di router.</p>
      </div>

      <div class="sp-isi" x-show="!d && galat" x-cloak>
        <p class="sp-galat" x-text="galat"></p>
        <p class="sp-catatan">Periksa IP, port, dan akun lewat tombol edit di <a class="link-strong" href="{{ route('routers.index') }}">Manajemen Router</a>, lalu periksa ulang.</p>
      </div>
    </li>

    <li class="card sp-step" :data-status="status('akun')">
      <div class="sp-kepala">
        <span class="sp-no" aria-hidden="true"><b>2</b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div class="min0">
          <h3>Akun API panel</h3>
          <p>Panel memakai akun sendiri dengan hak secukupnya, terpisah dari akun admin router.</p>
        </div>
        <span class="badge push-end" :class="nadaBadge('akun')" x-text="labelStatus('akun')"></span>
      </div>

      <template x-if="d">
        <div class="sp-isi">
          <p x-show="d.selesai.akun">
            Panel masuk memakai akun <b class="mono" x-text="d.akun.nama"></b>.
          </p>
          <p x-show="!d.selesai.akun">
            Panel sekarang masuk memakai akun <b class="mono" x-text="d.akun.dipakai"></b><span x-show="d.akun.grup" x-text="' (grup ' + d.akun.grup + ')'"></span>.
            <span x-text="d.akun.ada ? 'Akun ' + d.akun.nama + ' sudah ada di router; grup dan passwordnya akan diatur ulang lalu dipakai panel.' : 'Buat akun ' + d.akun.nama + ' supaya panel tidak memakai akun admin.'"></span>
          </p>

          <div class="sp-policy">
            <span class="label-caps">Hak akun</span>
            <template x-for="p in d.akun.policy" :key="p"><code x-text="p"></code></template>
          </div>
          <p class="sp-catatan" x-show="d.akun.policy.includes('ftp')">Hak ftp dipakai unduh backup selama pengiriman backup lewat SFTP belum diatur. Layanan FTP di router tidak diubah.</p>

          <ul class="sp-poin" x-show="!d.selesai.akun && d.akun.bisa_kelola">
            <li>Password dibuat acak, diuji dengan login baru, lalu disimpan terenkripsi. Tidak pernah ditampilkan.</li>
            <li>Akun <span class="mono" x-text="d.akun.dipakai"></span> tidak diubah atau dinonaktifkan.</li>
            <li>Kalau uji login gagal, panel tetap memakai akun lama.</li>
          </ul>

          <p class="sp-galat" x-show="!d.selesai.akun && !d.akun.bisa_kelola">
            Akun <span class="mono" x-text="d.akun.dipakai"></span> tidak punya hak mengelola user (policy dan write). Ganti kredensial router ke akun grup full lewat edit di Manajemen Router, lalu periksa ulang.
          </p>

          <div class="sp-aksi">
            <button type="button" class="btn btn-primary" x-show="!d.selesai.akun" :disabled="!d.akun.bisa_kelola || sibuk" @click="pasangAkun(false)">
              <span x-text="sibukAkun ? 'Menyiapkan akun…' : (d.akun.ada ? 'Pakai akun ' + d.akun.nama : 'Buat akun ' + d.akun.nama)"></span>
            </button>
            <button type="button" class="btn" x-show="d.selesai.akun" :disabled="sibuk" @click="pasangAkun(true)">
              <span x-text="sibukAkun ? 'Mengganti password…' : 'Ganti password'"></span>
            </button>
          </div>
        </div>
      </template>
      <div class="sp-isi sp-tunggu" x-show="!d">Menunggu koneksi.</div>
    </li>

    <li class="card sp-step" :data-status="status('script')">
      <div class="sp-kepala">
        <span class="sp-no" aria-hidden="true"><b>3</b><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div class="min0">
          <h3>Script login dan pengawas masa aktif</h3>
          <p>Script panel mencatat penjualan dan masa aktif saat voucher login; pengawas mengakhiri voucher yang habis.</p>
        </div>
        <span class="badge push-end" :class="nadaBadge('script')" x-text="labelStatus('script')"></span>
      </div>

      <template x-if="d">
        <div class="sp-isi">
          <dl class="sp-fakta">
            <div><dt>Script login</dt><dd :class="d.script.terpasang && !d.script.terbaru ? 't-warn' : ''" x-text="!d.script.terpasang ? 'belum ada' : (d.script.terbaru ? 'terpasang, versi terbaru' : 'versi lama')"></dd></div>
            <div><dt>Pengawas</dt><dd x-text="d.script.pengawas ? (d.script.pengawas.disabled ? 'nonaktif' : 'tiap ' + d.script.pengawas.interval) : 'belum ada'"></dd></div>
            <div><dt>Scheduler lama</dt><dd :class="d.script.lama.length ? 't-warn mono' : ''" x-text="d.script.lama.join(', ') || 'tidak ada'"></dd></div>
          </dl>

          <template x-if="!d.script.profil.length">
            <div class="sp-kosong">
              <p>Belum ada profil jualan (profil dengan masa aktif) di router ini<span x-show="d.script.profil_lain" x-text="'; ' + d.script.profil_lain + ' profil lain tanpa masa aktif'"></span>. Buat profil di halaman Paket; script dan pengawas ikut terpasang saat profil disimpan.</p>
              <a class="btn" href="{{ route('packages.index', ['router' => $router->slug]) }}">Buka Paket</a>
            </div>
          </template>

          <template x-if="d.script.profil.length">
            <div>
              <div class="tbl-wrap sp-tbl-wrap">
                <table class="tbl sp-tbl">
                  <thead>
                    <tr>
                      <th class="sp-cek" x-show="!d.selesai.script"><span class="sr-only">Pilih</span></th>
                      <th>Profil</th>
                      <th>Masa</th>
                      <th class="num">Harga</th>
                      <th>Saat habis</th>
                      <th>Sekarang</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template x-for="p in d.script.profil" :key="p.nama">
                      <tr>
                        <td class="sp-cek" x-show="!d.selesai.script">
                          <input type="checkbox" :value="p.nama" x-model="pilih" :disabled="!p.aman || sibuk" :aria-label="'Pasang script panel di ' + p.nama">
                        </td>
                        <td>
                          <span class="mono t-strong" x-text="p.nama"></span>
                          <div class="t-err fs-12" x-show="!p.aman">Nama berisi spasi atau tanda baca; ganti nama di Paket dulu.</div>
                        </td>
                        <td class="mono" x-text="p.validity"></td>
                        <td class="num" x-text="p.price ? rp(p.price) : '-'"></td>
                        <td x-text="labelMode(p)"></td>
                        <td>
                          <span class="badge ok no-dot" x-show="p.jenis === 'panel'">panel</span>
                          <span class="badge warn no-dot" x-show="p.jenis === 'lama'">script lama</span>
                          <span class="badge no-dot" x-show="p.jenis === 'kosong'">tanpa script</span>
                        </td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>

              <div class="sp-aksi" x-show="!d.selesai.script">
                <button type="button" class="btn btn-primary" :disabled="!pilih.length || sibuk" @click="pasangScript()">
                  <span x-text="sibukScript ? 'Memasang…' : 'Pasang script panel di ' + pilih.length + ' profil'"></span>
                </button>
                <span class="sp-catatan">Keadaan sekarang disimpan dulu untuk pemulihan. Sesi yang sedang online tidak diputus.</span>
              </div>
            </div>
          </template>
        </div>
      </template>
      <div class="sp-isi sp-tunggu" x-show="!d">Menunggu koneksi.</div>
    </li>

    <li class="card sp-step sp-lanjut">
      <div class="sp-kepala">
        <span class="sp-no" aria-hidden="true"><b>4</b></span>
        <div class="min0">
          <h3>Setelah siap</h3>
          <p>Penjualan dari router ini ditarik otomatis tiap 5 menit.</p>
        </div>
      </div>
      <div class="sp-isi">
        <nav class="sp-tautan" aria-label="Langkah berikutnya">
          <a href="{{ route('vouchers.index', ['impor' => $router->slug]) }}">
            <b>Impor voucher lama</b>
            <span>Salin voucher yang sudah ada di router ke panel.</span>
          </a>
          <a href="{{ route('packages.index', ['router' => $router->slug]) }}">
            <b>Atur profil dan harga</b>
            <span>Profil hotspot, masa aktif, dan harga di router ini.</span>
          </a>
          <a href="{{ route('vouchers.index', ['router' => $router->slug]) }}">
            <b>Buat voucher</b>
            <span>Generate dan cetak kartu untuk router ini.</span>
          </a>
        </nav>
      </div>
    </li>
  </ol>
</div>

@endsection

@push('scripts')
<script>
function siapkanRouter(cfg) {
  return {
    memuat: true, galat: '', d: null, pilih: [], sibukAkun: false, sibukScript: false,

    get sibuk() { return this.sibukAkun || this.sibukScript; },

    init() { this.periksa(); },

    async minta(url, opsi) {
      const res = await fetch(url, Object.assign({
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      }, opsi || {}));
      const data = await res.json().catch(() => ({}));
      if (res.status === 419) throw new Error('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
      if (res.status === 429) throw new Error('Terlalu sering. Tunggu sebentar lalu coba lagi.');
      if (!res.ok || data.success === false) {
        const e = data.errors ? Object.values(data.errors)[0][0] : null;
        throw new Error(e || data.error || data.message || ('HTTP ' + res.status));
      }
      return data;
    },

    async periksa() {
      this.memuat = true;
      this.galat = '';
      try {
        this.d = await this.minta(cfg.periksa);
        this.pilih = this.d.script.profil.filter(p => p.aman).map(p => p.nama);
      } catch (e) {
        this.d = null;
        this.galat = e.message;
      } finally {
        this.memuat = false;
      }
    },

    status(k) {
      if (!this.d) return this.memuat ? 'memuat' : (k === 'koneksi' ? 'terhambat' : 'menunggu');
      if (this.d.selesai[k]) return 'selesai';
      if (k === 'akun' && !this.d.akun.bisa_kelola) return 'terhambat';
      return 'perlu';
    },
    labelStatus(k) {
      return { memuat: 'Memeriksa…', menunggu: 'Menunggu', terhambat: 'Terhambat', selesai: 'Selesai', perlu: 'Perlu tindakan' }[this.status(k)];
    },
    nadaBadge(k) {
      return { selesai: 'ok', perlu: 'warn', terhambat: 'err' }[this.status(k)] || '';
    },
    jumlahSelesai() { return this.d ? Object.values(this.d.selesai).filter(Boolean).length : 0; },

    rp(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); },
    labelMode(p) { return p.mode === 'off' ? '-' : (p.mode === 'rem' ? 'hapus' : 'nonaktif') + (p.record ? ' + catat' : ''); },

    async pasangAkun(ganti) {
      const a = this.d.akun;
      const ok = await window.zeroConfirm(ganti ? {
        title: 'Ganti password akun panel',
        message: 'Password baru dibuat acak, diuji dengan login baru, lalu dipakai panel. Kalau uji gagal, password lama dikembalikan.',
        action: 'Ganti password',
      } : {
        title: a.ada ? 'Pakai akun ' + a.nama : 'Buat akun ' + a.nama,
        message: 'Panel ' + (a.ada ? 'mengatur ulang' : 'membuat') + ' grup dan akun ' + a.nama + ' di ' + this.d.koneksi.identitas
          + ', menguji login dengan akun itu, lalu memakainya. Akun ' + a.dipakai + ' tidak diubah.',
        action: a.ada ? 'Pakai akun' : 'Buat akun',
      });
      if (!ok) return;

      this.sibukAkun = true;
      try {
        const r = await this.minta(cfg.akun, { method: 'POST', body: '{}' });
        window.vgToast(r.message, 'ok');
        await this.periksa();
      } catch (e) {
        window.vgToast(e.message, 'err');
      } finally {
        this.sibukAkun = false;
      }
    },

    async pasangScript() {
      const dipilih = this.d.script.profil.filter(p => this.pilih.includes(p.nama));
      const lama = this.d.script.lama;
      const ok = await window.zeroConfirm({
        title: 'Pasang script panel',
        message: 'On-login ' + dipilih.length + ' profil (' + dipilih.map(p => p.nama).join(', ') + ') diganti ke script panel'
          + (lama.length ? ', dan scheduler lama ' + lama.join(', ') + ' dicabut' : '')
          + '. Pengawas masa aktif panel dipasang atau diperbarui.',
        action: 'Pasang',
      });
      if (!ok) return;

      this.sibukScript = true;
      try {
        const r = await this.minta(cfg.script, {
          method: 'POST',
          body: JSON.stringify({
            profiles: dipilih.map(p => ({ name: p.nama, mode: p.mode, record: p.record, lock: p.lock, validity: p.validity, price: p.price, sprice: p.sprice })),
            drop_legacy: true,
            snapshot: true,
          }),
        });
        window.vgToast(r.message, 'ok');
        await this.periksa();
      } catch (e) {
        window.vgToast(e.message, 'err');
      } finally {
        this.sibukScript = false;
      }
    },
  };
}
</script>
@endpush

@push('overlays')
  @include('vouchers._toast')
@endpush

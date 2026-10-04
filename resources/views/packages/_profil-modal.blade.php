<div class="mdl-backdrop" :class="terbuka ? 'is-open' : ''" x-show="modal === 'profil'" x-cloak role="dialog" aria-modal="true" aria-labelledby="pkProfilJudul"
     @click.self="!menyimpan && tutup()">
  <div class="mdl mdl-lg">
    <div class="mdl-head">
      <div class="grow min0">
        <h3 class="mdl-title" id="pkProfilJudul" x-text="fp && fp.asal ? 'Ubah profil ' + fp.asal : 'Tambah profil'"></h3>
        <p class="mdl-sub" x-text="'Router ' + router.name + '. Tersimpan langsung ke router.'"></p>
      </div>
      <button type="button" class="mdl-close" @click="!menyimpan && tutup()" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <template x-if="fp">
      <form class="mdl-body" autocomplete="off" @submit.prevent="simpanProfil()">
        <div class="mdl-alert" x-show="galat" x-text="galat" role="alert"></div>
        <div class="vp-warn" x-show="fp.asal && pakai(fp.asal) > 0">
          <b x-text="(pakai(fp.asal) || 0).toLocaleString('id-ID') + ' kartu'"></b> memakai profil ini. Kecepatan dan shared users langsung berlaku untuk semuanya.
          Masa aktif baru hanya berlaku untuk kartu yang belum pernah dipakai.
        </div>

        <div class="vp-sec">Profil hotspot</div>
        <div class="mdl-grid">
          <div class="field">
            <label for="pkNama">Nama profil <span class="req">*</span></label>
            <input id="pkNama" type="text" class="input mono" x-model.trim="fp.name" maxlength="31" placeholder="mis. 4-JAM">
            <small class="mdl-hint">Huruf, angka, titik, garis bawah, strip. Tanpa spasi.</small>
            <small class="mdl-err" :class="err.name ? 'on' : ''" x-text="err.name"></small>
          </div>
          <div class="field">
            <label for="pkRate">Rate limit</label>
            <input id="pkRate" type="text" class="input mono" x-model.trim="fp.rate_limit" placeholder="4M/8M">
            <small class="mdl-hint">upload/download. Kosong = tanpa batas.</small>
            <small class="mdl-err" :class="err.rate_limit ? 'on' : ''" x-text="err.rate_limit"></small>
          </div>
          <div class="field">
            <label for="pkShared">Shared users</label>
            <input id="pkShared" type="number" class="input mono" x-model="fp.shared_users" min="1" max="100">
            <small class="mdl-err" :class="err.shared_users ? 'on' : ''" x-text="err.shared_users"></small>
          </div>
          <div class="field">
            <label for="pkIdle">Idle timeout</label>
            <input id="pkIdle" type="text" class="input mono" x-model.trim="fp.idle_timeout" placeholder="biarkan kosong">
            <small class="mdl-err" :class="err.idle_timeout ? 'on' : ''" x-text="err.idle_timeout"></small>
          </div>
          <div class="field">
            <label for="pkPool">Address pool</label>
            <select id="pkPool" class="select" x-model="fp.address_pool">
              <option value="">none</option>
              <template x-for="n in pilihan('pools', fp.address_pool)" :key="n"><option :value="n" x-text="n"></option></template>
            </select>
          </div>
          <div class="field">
            <label for="pkQueue">Parent queue</label>
            <select id="pkQueue" class="select" x-model="fp.parent_queue">
              <option value="">none</option>
              <template x-for="n in pilihan('queues', fp.parent_queue)" :key="n"><option :value="n" x-text="n"></option></template>
            </select>
          </div>
        </div>

        <div class="vp-sec">Voucher</div>
        <div class="mdl-grid">
          <div class="field">
            <label for="pkMode">Saat masa aktif habis</label>
            <select id="pkMode" class="select" x-model="fp.mode">
              <option value="off">Tidak diawasi (untuk member)</option>
              <option value="ntf">Nonaktifkan kartu</option>
              <option value="rem">Hapus kartu dari router</option>
            </select>
          </div>
          <div class="field">
            <label for="pkMasa">Masa aktif <span class="req" x-show="fp.mode !== 'off'">*</span></label>
            <input id="pkMasa" type="text" class="input mono" x-model.trim="fp.validity" placeholder="mis. 1d">
            <small class="mdl-hint">Dihitung sejak login pertama.</small>
            <small class="mdl-err" :class="err.validity ? 'on' : ''" x-text="err.validity"></small>
          </div>
          <div class="field">
            <label for="pkHarga">Harga</label>
            <input id="pkHarga" type="number" class="input mono" x-model="fp.price" min="0" placeholder="5000">
            <small class="mdl-err" :class="err.price ? 'on' : ''" x-text="err.price"></small>
          </div>
          <div class="field">
            <label for="pkJual">Harga jual</label>
            <input id="pkJual" type="number" class="input mono" x-model="fp.sprice" min="0" placeholder="kosongkan kalau sama">
            <small class="mdl-err" :class="err.sprice ? 'on' : ''" x-text="err.sprice"></small>
          </div>
          <label class="pk-cek-baris vc-penuh">
            <input type="checkbox" x-model="fp.record">
            <span><b>Catat penjualan</b> · dibaca halaman Penjualan dan Cek Voucher</span>
          </label>
          <label class="pk-cek-baris vc-penuh">
            <input type="checkbox" x-model="fp.lock">
            <span><b>Kunci ke perangkat pertama</b> · kartu tidak bisa dipindah ke HP lain</span>
          </label>
        </div>

        <div class="vp-sec">Paket FreeRADIUS</div>
        <template x-if="fp.radius.ada">
          <p class="pk-radius-info">Paket <b class="mono" x-text="fp.radius.groupname"></b> memakai profil ini (<span x-text="fp.radius.pelanggan.toLocaleString('id-ID')"></span> pelanggan). Pengaturan paket berlaku di semua router.</p>
        </template>
        <label class="pk-cek-baris" x-show="!fp.radius.ada">
          <input type="checkbox" x-model="fp.radiusAktif">
          <span><b>Pelanggan bulanan juga memakai profil ini</b> · buat paket FreeRADIUS yang menunjuk ke profil ini</span>
        </label>
        <template x-if="fp.radius.ada || fp.radiusAktif">
          <div class="pk-radius">
            @include('packages._radius-fields', ['m' => 'fp.radius', 'id' => 'pkR'])
          </div>
        </template>
      </form>
    </template>

    <div class="mdl-foot">
      <span class="grow"></span>
      <button type="button" class="btn btn-sm" @click="tutup()" :disabled="menyimpan">Batal</button>
      <button type="button" class="btn btn-sm btn-primary" @click="simpanProfil()" :disabled="menyimpan" x-text="menyimpan ? 'Menyimpan…' : 'Simpan'"></button>
    </div>
  </div>
</div>

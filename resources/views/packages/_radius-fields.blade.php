<div class="mdl-grid">
  <div class="field">
    <label for="{{ $id }}Group">Nama paket <span class="req">*</span></label>
    <input id="{{ $id }}Group" type="text" class="input mono" x-model.trim="{{ $m }}.groupname" :disabled="{{ $m }}.ada" maxlength="64" placeholder="sama dengan nama profil">
    <small class="mdl-hint" x-show="!{{ $m }}.ada">Nama grup di FreeRADIUS. Tidak bisa diganti setelah dibuat.</small>
    <small class="mdl-err" :class="err.r_groupname ? 'on' : ''" x-text="err.r_groupname"></small>
  </div>
  <div class="field">
    <label for="{{ $id }}Nama">Nama untuk pelanggan</label>
    <input id="{{ $id }}Nama" type="text" class="input" x-model="{{ $m }}.display_name" maxlength="100" placeholder="mis. Paket Bulanan">
    <small class="mdl-hint">Tampil di portal pelanggan dan website. Kosong = nama paket.</small>
  </div>
  <div class="field">
    <label for="{{ $id }}Perangkat">Batas perangkat</label>
    <input id="{{ $id }}Perangkat" type="number" class="input mono" x-model="{{ $m }}.perangkat" min="1" max="100" placeholder="bebas">
    <small class="mdl-hint">Berapa perangkat satu akun pelanggan boleh login bersamaan.</small>
    <small class="mdl-err" :class="err.r_perangkat ? 'on' : ''" x-text="err.r_perangkat"></small>
  </div>
  <div class="field">
    <label for="{{ $id }}Kecepatan">Label kecepatan</label>
    <input id="{{ $id }}Kecepatan" type="text" class="input" x-model="{{ $m }}.speed_label" maxlength="50" placeholder="mis. 20 Mbps">
    <small class="mdl-hint">Hanya tulisan untuk pelanggan. Kecepatan asli mengikuti rate limit profil.</small>
  </div>
  <label class="pk-cek-baris vc-penuh">
    <input type="checkbox" x-model="{{ $m }}.is_active">
    <span><b>Aktif</b> · pelanggan di paket ini bisa login. Kalau dimatikan, semua pelanggannya ditolak.</span>
  </label>
  <label class="pk-cek-baris vc-penuh">
    <input type="checkbox" x-model="{{ $m }}.is_public">
    <span><b>Tampilkan di website</b> · muncul di halaman paket {{ config('app.domain') }}</span>
  </label>
  <template x-if="{{ $m }}.is_public">
    <div class="mdl-grid vc-penuh">
      <div class="field">
        <label for="{{ $id }}Masa">Masa (hari)</label>
        <input id="{{ $id }}Masa" type="number" class="input mono" x-model="{{ $m }}.validity_days" min="1" max="3650" placeholder="30">
        <small class="mdl-err" :class="err.r_validity_days ? 'on' : ''" x-text="err.r_validity_days"></small>
      </div>
      <div class="field">
        <label for="{{ $id }}Harga">Harga di website</label>
        <input id="{{ $id }}Harga" type="number" class="input mono" x-model="{{ $m }}.price" min="0" placeholder="200000">
        <small class="mdl-err" :class="err.r_price ? 'on' : ''" x-text="err.r_price"></small>
      </div>
      <label class="pk-cek-baris vc-penuh">
        <input type="checkbox" x-model="{{ $m }}.show_price">
        <span>Tampilkan harga ke calon pelanggan</span>
      </label>
      <div class="field vc-penuh">
        <label for="{{ $id }}Desk">Deskripsi singkat</label>
        <input id="{{ $id }}Desk" type="text" class="input" x-model="{{ $m }}.public_description" maxlength="255" placeholder="mis. Cocok untuk 3-5 perangkat">
      </div>
      <div class="field">
        <label for="{{ $id }}Urut">Urutan tampil</label>
        <input id="{{ $id }}Urut" type="number" class="input mono" x-model="{{ $m }}.sort_order" min="0" max="999">
        <small class="mdl-hint">Angka kecil tampil lebih dulu.</small>
      </div>
    </div>
  </template>
  <div class="field vc-penuh">
    <label for="{{ $id }}Catatan">Catatan internal</label>
    <input id="{{ $id }}Catatan" type="text" class="input" x-model="{{ $m }}.description" maxlength="255" placeholder="mis. Bulanan DIVISI 3 dan DIVISI 4">
  </div>
</div>

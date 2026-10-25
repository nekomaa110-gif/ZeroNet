@extends('layouts.app')

@section('title', 'Edit User: ' . $user['username'])
@section('page-title', 'Edit User Hotspot')

@section('page-class', 'pg-user-hotspot-edit')

@section('content')
  @php
    $invPertama = $openInvoices->first();
    $isAdmin    = (bool) auth()->user()?->isAdmin();
    $bisaTutup  = $openInvoices->count() === 1 && $isAdmin;
    $nilaiHarga = array_key_exists('price', (array) session()->getOldInput())
        ? (is_string(old('price')) ? old('price') : '')
        : ($harga ? number_format($harga, 0, ',', '.') : '');
  @endphp

  <header class="page-head">
    <div>
      <h2>Edit User: <span class="mono">{{ $user['username'] }}</span></h2>
      <p>Ubah paket, expire, password, atau nomor WhatsApp.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('user-hotspot.index', $kembali) }}" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  <form method="POST" action="{{ route('user-hotspot.update', $user['username']) }}"
        x-data="{
          showCurrent: false, changePass: false, showNew: false,
          expiryAwal: @js($user['expiry_input']), expiry: @js(old('expiry', $user['expiry_input']) ?? ''), aksi: @js(old('invoice_aksi') ?? ''),
          adaInvoice: @js($openInvoices->isNotEmpty()), bisaTutup: @js($bisaTutup),
          get memperpanjang() { return this.expiryAwal !== '' && (this.expiry === '' || this.expiry > this.expiryAwal); },
          get terkunci() { return this.adaInvoice && this.memperpanjang && (!this.bisaTutup || !this.aksi); },
          harga: @js($nilaiHarga),
          formatRibuan(nilai) { const angka = String(nilai ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, ''); return angka.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); },
          cancelChange() { this.changePass = false; this.showNew = false; var f = document.getElementById('password'); if (f) f.value = ''; }
        }"
        class="ue-form">
    @csrf @method('PUT')
    @foreach ($kembali as $k => $v)
      <input type="hidden" name="kembali[{{ $k }}]" value="{{ $v }}">
    @endforeach

    <div class="card">
      <div class="card-head">
        <div class="head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div>
          <h3>Identitas Akun</h3>
          <p class="sub-note">Username tetap. Password dan nomor WhatsApp bisa diganti.</p>
        </div>
      </div>
      <div class="card-pad stack-14">

        <div class="field">
          <label>Username</label>
          <input type="text" value="{{ $user['username'] }}" readonly tabindex="-1"
                 class="input mono" style="background:var(--bg-mute);color:var(--text-2);cursor:not-allowed" />
          <input type="hidden" name="username" value="{{ $user['username'] }}">
        </div>

        <div class="field">
          <label>Password Saat Ini</label>
          <div class="rel">
            <input :type="showCurrent ? 'text' : 'password'" value="{{ $user['password'] }}" readonly tabindex="-1"
                   class="input mono" style="background:var(--bg-mute);padding-right:40px" />
            <button type="button" @click="showCurrent = !showCurrent" tabindex="-1"
                    class="icon-btn"
                    style="position:absolute;right:6px;top:50%;transform:translateY(-50%);width:28px;height:28px;border:0;background:transparent">
              <svg x-show="!showCurrent" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg x-show="showCurrent" x-cloak width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <div class="hint">Klik ikon mata untuk melihat password aktif user.</div>
        </div>

        <div style="border:1px solid var(--border);border-radius:var(--r-md);overflow:hidden;">
          <button type="button" @click="changePass ? cancelChange() : (changePass = true)" class="ue-ganti"
                  style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border:0;background:var(--bg-mute);cursor:pointer">
            <div class="ue-ganti-judul" style="display:flex;align-items:center;gap:8px">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                   :style="changePass ? 'color:var(--brand-3)' : 'color:var(--text-3)'"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              <span style="font-weight:600;font-size:13px">Ganti Password</span>
            </div>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                 style="transition:transform .15s" :style="changePass ? 'transform:rotate(180deg)' : ''"><polyline points="6 9 12 15 18 9"/></svg>
          </button>

          <div x-show="changePass" x-cloak x-transition class="card-pad" style="border-top:1px solid var(--border);padding:14px;">
            <div class="field">
              <label>Password Baru</label>
              <div class="rel">
                <input :type="showNew ? 'text' : 'password'" id="password" name="password"
                       autocomplete="new-password" placeholder="Masukkan password baru..."
                       class="input" style="padding-right:40px" />
                <button type="button" @click="showNew = !showNew" tabindex="-1"
                        class="icon-btn"
                        style="position:absolute;right:6px;top:50%;transform:translateY(-50%);width:28px;height:28px;border:0;background:transparent">
                  <svg x-show="!showNew" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg x-show="showNew" x-cloak width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
              </div>
              @error('password') <div style="color:var(--err);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
              <div class="hint">Kosongkan untuk tidak mengubah password.</div>
            </div>
          </div>
        </div>

        <div class="field">
          <label for="phone">No. WhatsApp</label>
          <input type="tel" id="phone" name="phone" value="{{ old('phone', $phone) }}" inputmode="tel" autocomplete="off"
                 placeholder="0812..." class="input mono"
                 aria-describedby="phone-hint{{ $errors->has('phone') ? ' phone-error' : '' }}" @error('phone') aria-invalid="true" @enderror>
          @error('phone') <div id="phone-error" style="color:var(--err);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
          <div id="phone-hint" class="hint">{{ $phone ? 'Kosongkan untuk tidak mengubah nomor.' : 'Belum ada nomor tersimpan. Isi 0812..., 62812..., atau +62812...' }}</div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div>
          <h3>Paket &amp; Masa Berlaku</h3>
          <p class="sub-note">Profil RADIUS, tanggal kedaluwarsa, dan invoice terbuka.</p>
        </div>
      </div>
      <div class="card-pad stack-14">

        <div class="field">
          <label>Profil / Paket</label>
          <select id="group" name="group" class="select">
            <option value="">(Tidak ada)</option>
            @foreach($groups as $g)
              <option value="{{ $g }}" {{ (old('group', $user['group']) === $g) ? 'selected' : '' }}>{{ $g }}</option>
            @endforeach
          </select>
        </div>

        <div class="field">
          <label for="price">Harga (Rp)</label>
          @if ($gratis)
            <p class="ue-harga">Gratis</p>
          @elseif ($isAdmin)
            <input type="text" id="price" name="price" x-model="harga" @input="harga = formatRibuan($event.target.value)"
                   value="{{ $nilaiHarga }}" inputmode="numeric" autocomplete="off" class="input mono" placeholder="Belum ada harga"
                   aria-describedby="price-hint{{ $errors->has('price') ? ' price-error' : '' }}" @error('price') aria-invalid="true" @enderror>
            @error('price') <div id="price-error" style="color:var(--err);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
            @if ($saran)
              <div class="ue-saran">
                <span>Saran: {{ \App\Services\CustomerPriceService::rupiah($saran['amount']) }} dari invoice lunas #{{ $saran['invoice_id'] }}@if ($saran['tanggal']) ({{ $saran['tanggal']->format('d M Y') }})@endif</span>
                <button type="button" class="btn btn-sm" @click="harga = formatRibuan(@js($saran['amount']))">Pakai saran</button>
              </div>
            @endif
            <div class="hint" id="price-hint">Kosongkan lalu simpan untuk menghapus harga; user tanpa harga tidak ditagih otomatis.</div>
          @else
            <p class="ue-harga">{{ $harga ? \App\Services\CustomerPriceService::rupiah($harga) : 'Belum ada harga' }}</p>
          @endif
        </div>

        <div class="field">
          <label>Tanggal Expire</label>
          <input type="date" id="expiry" name="expiry" value="{{ old('expiry', $user['expiry_input']) }}" x-model="expiry" class="input">
          @error('expiry') <div style="color:var(--err);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
          <div class="hint">Kosongkan untuk menghapus expire. Waktu otomatis disimpan sebagai 23:59:59.</div>
        </div>

        @if ($invPertama)
          <div class="field">
            <label>Invoice terbuka</label>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:13px">
              <span class="mono">#{{ $invPertama->id }} · Rp {{ number_format($invPertama->amount, 0, ',', '.') }} · {{ $invPertama->customerStatusBadge()[1] }}</span>
              @if ($isAdmin)
                <a href="{{ route('billing.show', $invPertama->id) }}">Lihat invoice</a>
              @endif
            </div>
            @if ($bisaTutup)
              <input type="hidden" name="invoice_id" value="{{ $invPertama->id }}">
              <fieldset class="dr-inv-opsi" x-show="memperpanjang" x-cloak>
                <legend>Tanggal expire dimundurkan, invoice ini</legend>
                <label class="dr-inv-radio"><input type="radio" name="invoice_aksi" value="lunas" x-model="aksi"> Sudah dibayar, tandai lunas</label>
                <label class="dr-inv-radio"><input type="radio" name="invoice_aksi" value="batal" x-model="aksi"> Batalkan, perpanjang tanpa bayar</label>
              </fieldset>
              <p class="dr-inv-note" x-show="!memperpanjang">Invoice tidak berubah selama tanggal expire tidak dimundurkan.</p>
              <p class="dr-inv-note" x-show="memperpanjang && !aksi" x-cloak>Pilih dulu salah satu supaya invoice ikut ditutup.</p>
            @else
              <p class="dr-inv-note" x-show="memperpanjang" x-cloak>{{ $openInvoices->count() > 1
                ? "Ada {$openInvoices->count()} invoice terbuka. Rapikan dulu di halaman Tagihan sebelum memundurkan tanggal expire."
                : 'Hanya admin yang bisa memundurkan tanggal expire user yang masih punya invoice terbuka.' }}</p>
            @endif
            @error('invoice_aksi') <div style="color:var(--err);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
          </div>
        @endif
      </div>
    </div>

    <div class="ue-aksi">
      <button type="submit" class="btn btn-primary" :disabled="terkunci">Simpan Perubahan</button>
      <a href="{{ route('user-hotspot.index', $kembali) }}" class="btn">Batal</a>
    </div>
  </form>

@endsection

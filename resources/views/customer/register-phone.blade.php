@extends('customer.layout')
@section('title', 'Daftarkan Nomor HP · ZeroNet')
@section('content-class', 'narrow')

@section('content')
  <div class="page-intro">
    <div class="intro-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
      </svg>
    </div>
    <h1>Lengkapi data dulu</h1>
    <p>Daftarkan nomor HP supaya tagihan dan info perpanjangan bisa dikirim lewat WhatsApp.</p>
  </div>

  <div class="card">
    <form method="POST" action="{{ route('customer.phone.store') }}" novalidate>
      @csrf

      <div class="field">
        <label for="username">Akun</label>
        <input id="username" type="text" value="{{ $username }}" disabled>
      </div>

      <div class="field">
        <label for="phone">Nomor WhatsApp <span class="req" aria-hidden="true">*</span></label>
        <input id="phone" name="phone" type="tel" inputmode="tel"
               required autofocus value="{{ old('phone') }}"
               placeholder="08xx xxxx xxxx"
               autocomplete="tel">
        @error('phone') <div class="err">{{ $message }}</div> @enderror
        <p class="hint">Boleh diawali 0, 62, atau +62. Contoh: 081234567890</p>
      </div>

      <div class="field">
        <label for="name">Nama (opsional)</label>
        <input id="name" name="name" type="text" maxlength="100"
               value="{{ old('name') }}" placeholder="Bagaimana kami memanggilmu?">
      </div>

      <button class="btn btn-primary btn-block" type="submit">Simpan & Lanjutkan</button>
    </form>
  </div>

  <div class="note-box">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
    <div>
      Nomor ini dipakai untuk mengirim:
      <ul>
        <li>Tagihan perpanjangan paket bulanan</li>
        <li>Notifikasi pembayaran sudah dikonfirmasi</li>
        <li>Pengingat 1 hari sebelum akun expired</li>
      </ul>
    </div>
  </div>

  <div class="help-line">
    Salah masuk akun? <form method="POST" action="{{ route('customer.logout') }}" class="inline-form">@csrf
      <button type="submit" class="text-btn">Keluar</button>
    </form>
  </div>
@endsection

@extends('site.layouts.app')

@section('title', 'Cek Status Pelanggan · '.config('site.brand'))
@section('meta_description', 'Cek status layanan dan sisa masa aktif akun '.config('site.brand').' Anda.')

@push('head')
  <meta name="robots" content="noindex, follow">
@endpush

@php
    $wa = 'https://wa.me/'.config('site.whatsapp').'?text='
        .rawurlencode('Halo ZeroNet, saya mau tanya status layanan saya.');
@endphp

@section('content')

  <section class="section page-top">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Pelanggan</span>
        <h2>Cek status</h2>
        <p>Masukkan username WiFi atau nomor HP yang terdaftar.</p>
      </div>

      <div class="status-layout">
          <div class="card status-form">
            @if ($errors->any())
              <div class="alert err" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('site.status.check') }}">
              @csrf
              <div class="field">
                <label for="identitas">Username atau nomor HP</label>
                <input type="text" id="identitas" name="identitas" value="{{ old('identitas') }}"
                       autocomplete="off" autocapitalize="none" spellcheck="false"
                       placeholder="contoh: zero atau 08123456789" required>
              </div>
              <button type="submit" class="btn btn-primary btn-block">Cek status</button>
            </form>
          </div>

          <p class="side-note status-note">
            Pelanggan bulanan bisa melihat tagihan, mengunggah bukti transfer, dan meminta perpanjangan di
            <a href="{{ route('customer.login') }}">portal pelanggan</a>.
          </p>

        <div class="status-out" aria-live="polite">
          @if ($result)
            @php
              $tone = match (true) {
                  $result['expiry'] === null => 'warn',
                  $result['daysLeft'] <= 0   => 'err',
                  $result['daysLeft'] <= 7   => 'warn',
                  default                    => 'ok',
              };
            @endphp
            <div class="card status-result">
              <div class="sr-head">
                <div class="min0">
                  <span class="eyebrow">Hasil pengecekan</span>
                  <h3>{{ $result['name'] }}</h3>
                  <p><span class="mono">{{ $result['username'] }}</span> / {{ $result['package'] }}</p>
                </div>
                @if ($result['active'] === null)
                  <span class="pill soon"><span class="dot"></span>Perlu dicek admin</span>
                @elseif ($result['active'])
                  <span class="pill ok"><span class="dot"></span>Aktif</span>
                @else
                  <span class="pill err"><span class="dot"></span>Masa aktif habis</span>
                @endif
              </div>

              <div class="sr-big is-{{ $tone }}">
                <span class="eyebrow">Masa aktif</span>
                <b>
                  @if ($result['expiry'] === null)
                    Tidak terjadwal
                  @elseif ($result['daysLeft'] > 0)
                    {{ $result['daysLeft'] }} hari lagi
                  @elseif ($result['daysLeft'] === 0)
                    Berakhir hari ini
                  @else
                    Sudah berakhir
                  @endif
                </b>
                @if ($result['expiry'])
                  <small>sampai {{ $result['expiry']->translatedFormat('d M Y, H:i') }}</small>
                @endif
              </div>

              <dl class="kv">
                <div>
                  <dt>Koneksi</dt>
                  <dd>
                    @if ($result['online'])
                      <span class="pill ok"><span class="dot"></span>Online</span>
                    @else
                      <span class="pill"><span class="dot"></span>Tidak terhubung</span>
                    @endif
                  </dd>
                </div>
              </dl>

              @if ($result['active'] === false)
                <a href="{{ $wa }}" class="btn btn-wa btn-block sr-action" rel="noopener" target="_blank">Perpanjang lewat WhatsApp</a>
              @endif
            </div>
          @else
            <div class="card status-guide">
              <span class="eyebrow">Yang akan terlihat</span>
              <ol class="guide-list">
                <li><b>Status akun</b><span>Aktif, masa aktif habis, atau perlu dicek.</span></li>
                <li><b>Sisa masa aktif</b><span>Berapa hari lagi sebelum internet berhenti, lengkap dengan tanggalnya.</span></li>
                <li><b>Koneksi</b><span>Apakah akun sedang tersambung ke jaringan.</span></li>
              </ol>
              <p class="hint">Nama dan username ditampilkan sebagian untuk menjaga privasi.</p>
            </div>
          @endif
        </div>
      </div>
    </div>
  </section>

@endsection

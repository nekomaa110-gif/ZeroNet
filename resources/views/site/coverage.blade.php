@extends('site.layouts.app')

@section('title', 'Area Layanan · '.config('site.brand'))
@section('meta_description', 'Daftar wilayah yang sudah terjangkau jaringan '.config('site.brand').' beserta status ketersediaannya.')

@php
    $wa = 'https://wa.me/'.config('site.whatsapp').'?text='
        .rawurlencode('Halo ZeroNet, saya mau tanya ketersediaan layanan di lokasi saya.');
@endphp

@section('content')

  <section class="section page-top">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Jangkauan</span>
        <h2>Area layanan</h2>
        <p>Wilayah yang sudah terjangkau jaringan {{ config('site.brand') }}.</p>
      </div>

      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>Area</th><th>Status</th><th>Keterangan</th></tr>
          </thead>
          <tbody>
            @forelse ($areas as $area)
              <tr>
                <td><b>{{ $area->name }}</b></td>
                <td><span class="pill {{ $area->status === 'tersedia' ? 'ok' : 'soon' }}"><span class="dot"></span>{{ $area->statusLabel() }}</span></td>
                <td class="note">{{ $area->note ?: '' }}</td>
              </tr>
            @empty
              <tr><td colspan="3" class="note">Data area layanan sedang diperbarui. Silakan tanyakan langsung lewat WhatsApp.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <p class="price-note">Lokasi Anda belum terdaftar? Jaringan terus diperluas. Kirim alamat lengkap lewat WhatsApp agar bisa dijadwalkan survei.</p>
    </div>
  </section>

  <section class="section tight">
    <div class="wrap">
      <div class="cta-band">
        <div>
          <h2>Cek jangkauan di lokasi Anda</h2>
          <p>Sebutkan alamat atau titik lokasi, kami bantu cek jangkauan sinyal.</p>
        </div>
        <div class="actions">
          <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">Tanya lewat WhatsApp</a>
          <a href="{{ route('site.packages') }}" class="btn btn-ghost">Lihat paket</a>
        </div>
      </div>
    </div>
  </section>

@endsection

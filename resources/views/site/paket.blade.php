@extends('site.layouts.app')

@section('title', 'Paket Internet · '.config('site.brand'))
@section('meta_description', 'Daftar paket internet '.config('site.brand').' beserta kecepatan, harga, dan masa aktif.')

@php
    $wa = 'https://wa.me/'.config('site.whatsapp').'?text='.rawurlencode(config('site.whatsapp_text'));
@endphp

@section('content')

  <section class="section page-top">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Paket</span>
        <h2>Paket internet</h2>
        <p>Pilih paket sesuai kebutuhan. Untuk pemasangan baru, lokasi Anda perlu dicek dulu.</p>
      </div>

      @if ($packages->isEmpty())
        <div class="cta-band">
          <div>
            <h2>Daftar paket sedang diperbarui</h2>
            <p>Informasi paket dan harga terbaru bisa ditanyakan langsung lewat WhatsApp.</p>
          </div>
          <div class="actions">
            <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">Tanya paket lewat WhatsApp</a>
          </div>
        </div>
      @else
        <div class="price-board">
          @foreach ($packages as $package)
            @include('site.partials.plan', ['package' => $package])
          @endforeach
        </div>
        <p class="price-note">Harga dapat berubah sewaktu-waktu. Konfirmasi harga terbaru dan ketersediaan di lokasi Anda lewat tombol Pesan paket.</p>
      @endif
    </div>
  </section>

  <section class="section tight">
    <div class="wrap">
      <div class="cta-band">
        <div>
          <h2>Belum yakin pilih yang mana?</h2>
          <p>Ceritakan jumlah perangkat dan pemakaian harian Anda, kami bantu carikan yang paling pas.</p>
        </div>
        <div class="actions">
          <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">Konsultasi gratis</a>
          <a href="{{ route('site.coverage') }}" class="btn btn-ghost">Cek area layanan</a>
        </div>
      </div>
    </div>
  </section>

@endsection

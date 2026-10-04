@extends('site.layouts.app')

@section('title', config('site.brand').' · Internet Cepat dan Mudah')
@section('meta_description', config('site.description'))

@php
    $wa = 'https://wa.me/'.config('site.whatsapp').'?text='.rawurlencode(config('site.whatsapp_text'));
    $areasReady = $areas->where('status', 'tersedia')->count();
    $hours = config('site.hours', []);
    $priceHidden = $packages->contains(fn ($p) => ! $p->priceLabel());
@endphp

@section('content')

  <section class="hero" id="beranda" data-spy-section>
    <div class="wrap">
      <div>
        <span class="eyebrow reveal">Internet rumah dan usaha</span>
        <h1 class="reveal">{{ config('site.tagline') }}</h1>
        <p class="lead reveal">WiFi dengan paket harian, mingguan, dan bulanan. Pemasangan dibantu sampai jalan, gangguan ditangani tim yang ada di sekitar Anda.</p>
        <div class="actions reveal">
          <a href="{{ route('site.packages') }}" class="btn btn-primary">Lihat paket</a>
          <a href="{{ $wa }}" class="btn btn-ghost" rel="noopener" target="_blank">Tanya lewat WhatsApp</a>
        </div>
      </div>

      <aside class="hero-board" aria-label="Info layanan">
        <h2>Info layanan</h2>
        @if ($areas->isNotEmpty())
          <dl>
            <div><dt>Area tersedia</dt><dd>{{ $areasReady }} dari {{ $areas->count() }}</dd></div>
          </dl>
        @endif
        @if ($hours)
          <h3>Jam layanan</h3>
          <dl>
            @foreach ($hours as $day => $time)
              <div><dt>{{ $day }}</dt><dd>{{ $time }}</dd></div>
            @endforeach
          </dl>
        @endif
        <div class="board-foot"><span class="led ok" aria-hidden="true"></span>WA +{{ config('site.whatsapp') }}</div>
      </aside>
    </div>
  </section>

  <section class="section">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Kenapa {{ config('site.brand') }}</span>
        <h2>Sederhana, jelas, dekat.</h2>
      </div>

      <ol class="features">
        @foreach (config('site.highlights', []) as $item)
          <li>
            <span class="num">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
            <h3>{{ $item['title'] }}</h3>
            <p>{{ $item['text'] }}</p>
          </li>
        @endforeach
      </ol>
    </div>
  </section>

  @if ($packages->isNotEmpty())
    <section class="section" id="paket" data-spy-section>
      <div class="wrap">
        <div class="section-head">
          <span class="eyebrow">Paket</span>
          <h2>Paket populer</h2>
          <p>Pilih yang paling pas dengan pemakaian harian Anda.</p>
          <a href="{{ route('site.packages') }}" class="btn btn-ghost more">Semua paket</a>
        </div>

        <div class="price-board">
          @foreach ($packages as $package)
            @include('site.partials.plan', ['package' => $package])
          @endforeach
        </div>
        @if ($priceHidden)
          <p class="price-note">Untuk harga, tanyakan lewat tombol Pesan paket.</p>
        @endif
      </div>
    </section>
  @endif

  @if ($areas->isNotEmpty())
    <section class="section" id="area" data-spy-section>
      <div class="wrap">
        <div class="section-head">
          <span class="eyebrow">Jangkauan</span>
          <h2>Area layanan</h2>
          <p>Jaringan {{ config('site.brand') }} terus diperluas ke area sekitar.</p>
          <a href="{{ route('site.coverage') }}" class="btn btn-ghost more">Detail area</a>
        </div>

        <ul class="area-list">
          @foreach ($areas->take(6) as $area)
            <li>
              <span class="area-name">{{ $area->name }}</span>
              <span class="pill {{ $area->status === 'tersedia' ? 'ok' : 'soon' }}"><span class="dot"></span>{{ $area->statusLabel() }}</span>
            </li>
          @endforeach
        </ul>
      </div>
    </section>
  @endif

  <section class="section tight">
    <div class="wrap">
      <div class="cta-band">
        <div>
          <h2>Siap pasang internet?</h2>
          <p>Kami cek dulu jangkauan di lokasi Anda, lalu atur jadwal pemasangan.</p>
        </div>
        <div class="actions">
          <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.86 9.86 0 0 0 4.74 1.2c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.13-2.9-7A9.82 9.82 0 0 0 12.04 2Z"/></svg>
            Chat lewat WhatsApp
          </a>
          <a href="{{ route('site.help') }}" class="btn btn-ghost">Pusat bantuan</a>
        </div>
      </div>
    </div>
  </section>

@endsection

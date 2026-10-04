@extends('site.layouts.app')

@section('title', 'Bantuan · '.config('site.brand'))
@section('meta_description', 'Pusat bantuan '.config('site.brand').': kontak WhatsApp customer service, jam operasional, panduan singkat, dan pertanyaan yang sering diajukan.')

@php
    $waBase = 'https://wa.me/'.config('site.whatsapp').'?text=';
    $wa     = $waBase.rawurlencode(config('site.whatsapp_text'));
@endphp

@section('content')

  <section class="section page-top">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Bantuan</span>
        <h2>Ada kendala?</h2>
        <p>Pilih keperluan Anda supaya kami bisa langsung membantu.</p>
      </div>

      <div class="contact-strip">
        <div class="card">
          <h3>WhatsApp</h3>
          <span class="wa-number">+{{ config('site.whatsapp') }}</span>
          <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">Chat sekarang</a>
        </div>
        <div class="card">
          <h3>Jam operasional</h3>
          <dl class="hours-list">
            @foreach (config('site.hours', []) as $day => $time)
              <div><dt>{{ $day }}</dt><dd>{{ $time }}</dd></div>
            @endforeach
          </dl>
        </div>
      </div>

      <div class="section-head sub">
        <h2>Pilih keperluan</h2>
        <p>Tombol membuka WhatsApp dengan pesan yang sudah terisi.</p>
      </div>
      <ul class="topic-list">
        @foreach (config('site.help_topics', []) as $topic)
          <li>
            <a href="{{ $waBase.rawurlencode('Halo ZeroNet, saya butuh bantuan: '.$topic.'.') }}" rel="noopener" target="_blank">
              {{ $topic }}
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>
          </li>
        @endforeach
      </ul>

      <div class="section-head sub">
        <h2>Coba dulu sebelum lapor</h2>
        <p>Sebagian gangguan selesai dalam satu dua menit.</p>
      </div>
      <ol class="steps">
        <li>
          <h3>Matikan dan nyalakan WiFi</h3>
          <p>Matikan WiFi di HP atau laptop, tunggu 10 detik, lalu sambungkan ulang.</p>
        </li>
        <li>
          <h3>Restart perangkat</h3>
          <p>Cabut adaptor listrik router atau perangkat penerima selama 30 detik, lalu pasang lagi.</p>
        </li>
        <li>
          <h3>Cek masa aktif</h3>
          <p>Masa aktif yang habis membuat internet berhenti.
            @if (config('site.status_check_enabled'))
              Cek lewat halaman <a href="{{ route('site.status') }}">Cek Status</a>.
            @else
              Tanyakan lewat WhatsApp.
            @endif
          </p>
        </li>
      </ol>

      <div class="section-head sub">
        <h2>Pertanyaan yang sering ditanyakan</h2>
      </div>
      <div class="faq">
        @foreach (config('site.faq', []) as $item)
          <details @if($loop->first) open @endif>
            <summary>{{ $item['q'] }}</summary>
            <div class="answer">{{ $item['a'] }}</div>
          </details>
        @endforeach
      </div>
    </div>
  </section>

  <section class="section tight">
    <div class="wrap">
      <div class="cta-band">
        <div>
          <h2>Masalah belum selesai?</h2>
          <p>Sebutkan nama dan alamat pemasangan supaya penanganan lebih cepat.</p>
        </div>
        <div class="actions">
          <a href="{{ $wa }}" class="btn btn-wa" rel="noopener" target="_blank">Chat lewat WhatsApp</a>
        </div>
      </div>
    </div>
  </section>

@endsection

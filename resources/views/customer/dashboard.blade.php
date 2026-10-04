@extends('customer.layout')
@section('title', 'Beranda · ZeroNet')
@section('content-class', 'grid-cards')

@php
  $statusLabel = match (true) {
    $expiry === null    => ['warn', 'Status tidak diketahui'],
    $isExpired === true => ['err',  'Tidak Aktif'],
    default             => ['ok',   'Aktif'],
  };

  $tier = match (true) {
    $expiry === null  => 'warn',
    $daysLeft <= 1    => 'err',
    $daysLeft <= 7    => 'warn',
    default           => 'ok',
  };

  $remainText = match (true) {
    $expiry === null => null,
    $daysLeft < 0    => 'Berakhir ' . abs($daysLeft) . ' hari lalu',
    $daysLeft === 0  => 'Berakhir hari ini',
    default          => 'Sisa ' . $daysLeft . ' hari',
  };

  $meterPct = $daysLeft !== null && $daysLeft > 0 ? min(100, round($daysLeft / 30 * 100)) : 0;

  $waHelp = 'https://wa.me/' . $businessWa . '?text=' . rawurlencode("Halo admin, saya {$username}. Saya butuh bantuan terkait layanan internet saya.");
@endphp

@section('content')
  <div class="hello page-wide">
    <span>Halo,</span>
    <b>{{ $name ?: $username }}</b>
  </div>

  @if (empty($phone))
    <div class="alert warn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
      <div>
        Nomor HP belum terdaftar. Hubungi admin WA
        <a href="https://wa.me/{{ $businessWa }}">0831-7081-2664</a>
        agar invoice juga bisa dikirim lewat WhatsApp.
      </div>
    </div>
  @endif

  <section class="card" aria-label="Langganan">
    <div class="sub-head">
      <div class="pkg">
        <div class="icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        </div>
        <div>
          <div class="name">{{ $package?->customerName() ?? 'Paket Internet' }}</div>
          <div class="speed">{{ $package?->speed_label ?: 'Langganan bulanan' }}</div>
        </div>
      </div>
      <span class="status {{ $statusLabel[0] }}"><span class="dot"></span>{{ $statusLabel[1] }}</span>
    </div>

    <div class="divide"></div>

    <div class="stat-big">
      <span class="l">Aktif sampai</span>
      <span class="v">
        @if ($expiry)
          {{ $expiry->translatedFormat('d M Y') }} <small>{{ $expiry->format('H:i') }}</small>
        @else
          <small>Belum terjadwal</small>
        @endif
      </span>
    </div>

    @if ($remainText)
      <div class="remain {{ $tier }}">{{ $remainText }}</div>
      @if ($daysLeft > 0)
        <div class="meter {{ $tier }}" role="img" aria-label="Sisa masa aktif {{ $meterPct }} persen dari 30 hari"><i style="width: {{ $meterPct }}%"></i></div>
      @endif
    @endif

    <div class="card-actions">
      @if ($canRequestRenewal)
        <form method="POST" action="{{ route('customer.renew.request') }}">@csrf
          <button class="btn btn-primary btn-block" type="submit">Perpanjang sekarang</button>
        </form>
        <p class="btn-note">
          @if ($expiry === null)
            Masa aktif belum tercatat. Ajukan perpanjangan bila internet tidak bisa dipakai.
          @elseif ($isExpired)
            Akun sudah berakhir. Ajukan perpanjangan untuk aktif kembali.
          @else
            Masa aktif tinggal kurang dari 24 jam.
          @endif
        </p>
      @else
        <button class="btn btn-block" type="button" disabled>Perpanjang sekarang</button>
        <p class="btn-note">
          @if ($hasOpenRequest)
            Tagihan perpanjangan sudah dibuat. Selesaikan pembayaran di bawah.
          @elseif ($renewalOpensAt)
            Perpanjangan bisa diajukan mulai <b>{{ $renewalOpensAt->translatedFormat('d M Y') }}</b> (H-1 sebelum masa aktif habis).
          @else
            Perpanjangan bisa diajukan H-1 sebelum masa aktif habis.
          @endif
        </p>
      @endif
    </div>
  </section>

  @if ($openInvoice?->status === \App\Models\Invoice::STATUS_DRAFT)
    <div class="alert info">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
      <span>Permintaan perpanjangan <b>sedang diproses</b>. Admin akan segera membuat tagihan.</span>
    </div>
  @elseif ($openInvoice?->status === \App\Models\Invoice::STATUS_UNPAID)
    <section class="card tone-warn" aria-label="Tagihan aktif">
      <h2>Tagihan aktif</h2>
      <div class="stat-big">
        <span class="l">Total</span>
        <span class="v">Rp {{ number_format($openInvoice->amount, 0, ',', '.') }}</span>
      </div>
      <div class="card-actions">
        <a class="btn btn-primary btn-block" href="{{ route('customer.invoice.show', $openInvoice) }}">Bayar sekarang</a>
      </div>
    </section>
  @elseif ($openInvoice?->status === \App\Models\Invoice::STATUS_PENDING)
    <div class="alert info">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
      <span>Bukti pembayaran sudah diterima. <b>Menunggu konfirmasi admin.</b></span>
    </div>
  @endif

  @if ($invoices->count())
    <section class="card" aria-label="Riwayat tagihan">
      <h2>Riwayat tagihan</h2>
      @foreach ($invoices as $inv)
        @include('customer.invoice._item', ['inv' => $inv])
      @endforeach

      @if ($invoiceCount > $invoices->count())
        <div class="card-actions">
          <a class="btn btn-secondary btn-block" href="{{ route('customer.invoice.index') }}">Lihat semua riwayat ({{ $invoiceCount }})</a>
        </div>
      @endif
    </section>
  @endif
@endsection

@section('fab')
  @include('site.partials.wa-button', ['href' => $waHelp])
@endsection

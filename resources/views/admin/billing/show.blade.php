@extends('layouts.app')

@section('title', 'Invoice #' . $invoice->id)
@section('page-title', 'Detail Invoice')

@php
  use App\Models\Invoice;
  $statusBadge = [
    Invoice::STATUS_DRAFT     => ['yellow', 'Diproses'],
    Invoice::STATUS_UNPAID    => ['yellow', 'Belum Bayar'],
    Invoice::STATUS_PENDING   => ['blue',   'Menunggu Konfirmasi'],
    Invoice::STATUS_PAID      => ['green',  'Lunas'],
    Invoice::STATUS_CANCELLED => ['red',    'Dibatalkan'],
  ];
  $b = $statusBadge[$invoice->status] ?? ['gray', $invoice->status];
@endphp

@section('content')
  <header class="page-head">
    <div>
      <h2 class="title-inline">
        Invoice <span class="sub mono">#{{ $invoice->id }}</span>
        <x-admin.badge :color="$b[0]" :dot="true">{{ $b[1] }}</x-admin.badge>
      </h2>
      <p>{{ $invoice->username }} / {{ $invoice->profile }} / dibuat {{ optional($invoice->created_at)->translatedFormat('d M Y H:i') }}</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('billing.index') }}" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  <div class="detail-grid">
    <div class="detail-main">
      <section class="panel bill-hero" aria-label="Ringkasan tagihan">
        <div>
          <div class="eyebrow">Total tagihan</div>
          @if ($invoice->amount > 0)
            <div class="hero-amount"><span class="rp">Rp</span>{{ number_format($invoice->amount, 0, ',', '.') }}</div>
          @else
            <div class="hero-amount is-empty">Belum ditetapkan</div>
          @endif
        </div>
        @if ($invoice->extended_to)
          <div class="hero-extend">
            <div class="eyebrow">Akun aktif sampai</div>
            <div class="hero-extend-value">{{ $invoice->extended_to->translatedFormat('d M Y') }}</div>
            <div class="hero-extend-time">pukul {{ $invoice->extended_to->format('H:i') }}</div>
          </div>
        @elseif ($invoice->due_date)
          @php $lewat = ! $invoice->isPaid() && $invoice->status !== Invoice::STATUS_CANCELLED && $invoice->due_date->copy()->startOfDay()->lt(today()); @endphp
          <div class="hero-extend">
            <div class="eyebrow">Jatuh tempo</div>
            <div class="hero-extend-value {{ $lewat ? 't-err' : 't-strong' }}">{{ $invoice->due_date->translatedFormat('d M Y') }}</div>
            @if ($lewat)
              <div class="hero-extend-time t-err">lewat {{ (int) $invoice->due_date->copy()->startOfDay()->diffInDays(today()) }} hari</div>
            @endif
          </div>
        @endif
      </section>

      <section class="panel">
        <h3 class="panel-title">Detail tagihan</h3>
        <dl class="kvl cols-2">
          <div><dt>Username</dt><dd>{{ $invoice->username }}</dd></div>
          <div><dt>Profile</dt><dd>{{ $invoice->profile }}</dd></div>
          <div><dt>Jumlah</dt>
            <dd>@if ($invoice->amount > 0) Rp {{ number_format($invoice->amount, 0, ',', '.') }} @endif</dd>
          </div>
          <div><dt>Jatuh tempo</dt>
            <dd>{{ optional($invoice->due_date)->translatedFormat('d M Y') ?? '' }}</dd>
          </div>
          @if ($invoice->bank_to)
            <div><dt>Rekening tujuan</dt>
              <dd>{{ strtoupper(str_replace('-', ' / ', $invoice->bank_to)) }}</dd>
            </div>
          @endif
          @if ($invoice->notes)
            <div class="full"><dt class="eyebrow">Catatan</dt><dd>{{ $invoice->notes }}</dd></div>
          @endif
        </dl>
      </section>

      <section class="panel">
        <h3 class="panel-title">Riwayat</h3>
        <ul class="timeline">
          <li>
            <span class="tl-dot done"></span>
            <div class="tl-body">
              <b>Invoice dibuat</b>
              <span class="t">{{ optional($invoice->created_at)->translatedFormat('d M Y, H:i') }}</span>
            </div>
          </li>
          @if ($invoice->paid_at)
            <li>
              <span class="tl-dot done"></span>
              <div class="tl-body">
                <b>Bukti pembayaran diunggah</b>
                <span class="t">{{ $invoice->paid_at->translatedFormat('d M Y, H:i') }}</span>
              </div>
            </li>
          @elseif (in_array($invoice->status, [Invoice::STATUS_UNPAID]))
            <li>
              <span class="tl-dot pending"></span>
              <div class="tl-body"><b class="muted">Menunggu pelanggan transfer</b></div>
            </li>
          @endif
          @if ($invoice->confirmed_at)
            <li>
              <span class="tl-dot done"></span>
              <div class="tl-body">
                <b>Pembayaran dikonfirmasi</b>
                <span class="t">{{ $invoice->confirmed_at->translatedFormat('d M Y, H:i') }}</span>
              </div>
            </li>
          @elseif ($invoice->status === Invoice::STATUS_PENDING)
            <li>
              <span class="tl-dot pending"></span>
              <div class="tl-body"><b class="muted">Menunggu konfirmasi admin</b></div>
            </li>
          @endif
          @if ($invoice->extended_to && $invoice->isPaid())
            <li>
              <span class="tl-dot ok"></span>
              <div class="tl-body"><b>Akun diperpanjang sampai {{ $invoice->extended_to->translatedFormat('d M Y') }}</b></div>
            </li>
          @endif
          @if ($invoice->status === Invoice::STATUS_CANCELLED)
            <li>
              <span class="tl-dot err"></span>
              <div class="tl-body"><b class="t-err">Invoice dibatalkan</b></div>
            </li>
          @endif
        </ul>
      </section>

      @if ($invoice->payment_proof)
        <section class="panel">
          <h3 class="panel-title">
            Bukti transfer
            <a href="{{ asset('storage/' . $invoice->payment_proof) }}" target="_blank" rel="noopener" class="btn btn-sm">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>
              Buka asli
            </a>
          </h3>
          <a href="{{ asset('storage/' . $invoice->payment_proof) }}" target="_blank" rel="noopener" class="proof-img">
            <img src="{{ asset('storage/' . $invoice->payment_proof) }}" alt="Bukti transfer invoice #{{ $invoice->id }}">
          </a>
        </section>
      @endif
    </div>

    <aside class="detail-side">
      <section class="panel">
        <h3 class="panel-title">Tindakan</h3>

        @if ($invoice->status === Invoice::STATUS_DRAFT)
          <p class="action-hint">Permintaan dari pelanggan. Tetapkan harga dan jatuh tempo, lalu publikasikan.</p>
          <form method="POST" action="{{ route('billing.publish', $invoice) }}" class="action-form">
            @csrf
            <div class="field">
              <label for="pub-amount">Jumlah (Rp)</label>
              <input id="pub-amount" name="amount" type="number" class="input mono" min="1000" step="1000" value="{{ old('amount', 200000) }}" required>
              <div class="action-chips" role="group" aria-label="Harga cepat">
                <button type="button" class="chip" onclick="this.form.amount.value=200000">200.000</button>
                <button type="button" class="chip" onclick="this.form.amount.value=175000">175.000</button>
                <button type="button" class="chip" onclick="this.form.amount.value=170000">170.000</button>
              </div>
            </div>
            <div class="field">
              <label for="pub-due">Jatuh tempo</label>
              <input id="pub-due" name="due_date" type="date" class="input" value="{{ old('due_date', now()->addDays(7)->toDateString()) }}" required>
            </div>
            <button class="btn btn-primary action-cta" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4 20-7z"/></svg>
              Publikasikan dan kirim
            </button>
          </form>
        @endif

        @if (in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING]))
          <p class="action-hint">
            @if ($invoice->status === Invoice::STATUS_PENDING)
              Bukti sudah diunggah. Cek bukti transfer lalu konfirmasi.
            @else
              Pelanggan belum unggah bukti. Konfirmasi manual kalau mutasi sudah dicek.
            @endif
          </p>
          <form method="POST" action="{{ route('billing.confirm', $invoice) }}"
                data-confirm="Konfirmasi pembayaran sudah masuk? Akun pelanggan akan diperpanjang +30 hari dari sekarang."
                data-confirm-title="Konfirmasi Pembayaran"
                data-confirm-action="Konfirmasi Lunas"
                data-confirm-variant="info">
            @csrf
            <button class="btn btn-ok action-cta" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
              Konfirmasi lunas
            </button>
          </form>
        @endif

        @if (in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING]))
          <details class="action-cancel">
            <summary>Batalkan invoice</summary>
            <form method="POST" action="{{ route('billing.cancel', $invoice) }}"
                  data-confirm="Batalkan invoice ini? Status akan jadi 'Cancelled' dan tidak bisa diaktifkan kembali."
                  data-confirm-title="Batalkan Invoice"
                  data-confirm-action="Batalkan"
                  data-confirm-variant="warning">
              @csrf
              <div class="field">
                <label for="cancel-reason">Alasan <span class="t-mute">(opsional)</span></label>
                <input id="cancel-reason" name="reason" type="text" placeholder="contoh: pelanggan pindah" class="input">
              </div>
              <button class="btn btn-outline-err action-cta" type="submit">Batalkan invoice</button>
            </form>
          </details>
        @endif

        @if ($invoice->status === Invoice::STATUS_PAID)
          <div class="state-box ok">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div><b>Lunas</b><br><span class="muted">Akun sudah diperpanjang +30 hari</span></div>
          </div>
        @endif

        @if ($invoice->status === Invoice::STATUS_CANCELLED)
          <div class="state-box err">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <div><b>Dibatalkan</b><br><span class="muted">Invoice ini sudah dibatalkan</span></div>
          </div>
        @endif
      </section>

      <section class="panel">
        <h3 class="panel-title">Pelanggan</h3>
        <dl class="kvl">
          <div><dt>Nama</dt><dd>{{ $contact?->name ?? '' }}</dd></div>
          <div><dt>No HP</dt>
            <dd>
              @if ($contact?->phone)
                <a class="link-ext" href="https://wa.me/{{ preg_replace('/^0/', '62', $contact->phone) }}" target="_blank" rel="noopener">
                  {{ $contact->phone }}
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>
                </a>
              @else
                <span class="t-mute">belum terdaftar</span>
              @endif
            </dd>
          </div>
          <div><dt>Username</dt><dd><code>{{ $invoice->username }}</code></dd></div>
          <div><dt>Aktif sampai</dt>
            <dd>
              @if ($expiry)
                <span class="{{ $expiry->isPast() ? 't-err' : 't-ok' }}">{{ $expiry->translatedFormat('d M Y') }}</span>
              @endif
            </dd>
          </div>
        </dl>
      </section>
    </aside>
  </div>
@endsection

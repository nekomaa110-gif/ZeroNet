@extends('customer.layout')
@section('title', 'Tagihan #' . $invoice->id . ' · ZeroNet')

@php
  use App\Models\Invoice as InvoiceModel;
  $statusMap = [
    InvoiceModel::STATUS_DRAFT     => ['info', 'Diproses Admin'],
    InvoiceModel::STATUS_UNPAID    => ['warn', 'Menunggu Pembayaran'],
    InvoiceModel::STATUS_PENDING   => ['info', 'Menunggu Konfirmasi'],
    InvoiceModel::STATUS_PAID      => ['ok',   'Lunas'],
    InvoiceModel::STATUS_CANCELLED => ['err',  'Dibatalkan'],
  ];
  $st = $statusMap[$invoice->status];
@endphp

@section('content')
  <a class="backlink" href="{{ route('customer.dashboard') }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    Kembali
  </a>

  <div class="card">
    <div class="sub-head">
      <div class="stat-big">
        <span class="l">Tagihan</span>
        <span class="v mono">#{{ $invoice->id }}</span>
        <span class="muted">{{ $invoice->profile }}</span>
      </div>
      <span class="status {{ $st[0] }}"><span class="dot"></span>{{ $st[1] }}</span>
    </div>

    <div class="divide"></div>

    <dl class="inv-kv">
      <div>
        <dt>Total</dt>
        <dd class="inv-total">
          @if ($invoice->amount > 0)
            Rp {{ number_format($invoice->amount, 0, ',', '.') }}
          @else
            <span class="muted">Belum ditetapkan</span>
          @endif
        </dd>
      </div>

      @if ($invoice->due_date)
        <div><dt>Jatuh tempo</dt><dd>{{ $invoice->due_date->translatedFormat('d M Y') }}</dd></div>
      @endif
      @if ($invoice->isPaid() && $invoice->extended_to)
        <div><dt>Aktif sampai</dt><dd class="remain ok">{{ $invoice->extended_to->translatedFormat('d M Y') }}</dd></div>
      @endif
    </dl>
  </div>

  @if ($invoice->status === InvoiceModel::STATUS_DRAFT)
    <div class="alert info">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
      <span>Permintaan perpanjangan <b>sedang diproses</b>. Mohon tunggu admin menetapkan harga.</span>
    </div>
  @endif

  @if ($invoice->status === InvoiceModel::STATUS_UNPAID)
    <div class="card">
      <h2>Upload bukti transfer</h2>
      <p class="muted lead-line">Pilih rekening tujuan, transfer sejumlah tagihan, lalu upload foto buktinya.</p>
      <form method="POST" action="{{ route('customer.invoice.upload-proof', $invoice) }}" enctype="multipart/form-data">
        @csrf

        <div class="field">
          <label for="bank_to">Transfer ke rekening</label>
          <select id="bank_to" name="bank_to" required>
            <option value="">- pilih rekening -</option>
            @foreach ($banks as $b)
              <option value="{{ $b['key'] }}" data-account="{{ $b['account'] }}">{{ $b['bank'] }} · {{ $b['account'] }} · {{ $b['holder'] }}</option>
            @endforeach
          </select>
          <div id="acct-copy" class="acct-copy" hidden>
            <span id="acct-number" class="mono"></span>
            <button type="button" id="acct-btn" class="btn btn-secondary">Salin</button>
          </div>
          @error('bank_to') <div class="err">{{ $message }}</div> @enderror
        </div>

        <div class="field">
          <label for="payment_proof">Foto bukti transfer <span class="muted">(JPG/PNG, maks 8 MB)</span></label>
          <input id="payment_proof" name="payment_proof" type="file" accept="image/*" required />
          <p class="hint">Pilih dari galeri atau ambil foto baru.</p>
          @error('payment_proof') <div class="err">{{ $message }}</div> @enderror
        </div>

        <button class="btn btn-primary btn-block" type="submit">Kirim bukti pembayaran</button>
      </form>
    </div>

    <script>
      (function () {
        var sel = document.getElementById('bank_to');
        var box = document.getElementById('acct-copy');
        var num = document.getElementById('acct-number');
        var btn = document.getElementById('acct-btn');

        function sync() {
          var acct = sel.options[sel.selectedIndex]?.dataset.account || '';
          num.textContent = acct;
          box.hidden = acct === '';
          btn.textContent = 'Salin';
        }

        btn.addEventListener('click', function () {
          navigator.clipboard.writeText(num.textContent).then(function () {
            btn.textContent = 'Tersalin';
            setTimeout(function () { btn.textContent = 'Salin'; }, 1500);
          });
        });

        sel.addEventListener('change', sync);
        sync();
      })();
    </script>
  @endif

  @if ($invoice->status === InvoiceModel::STATUS_PENDING)
    <div class="alert info">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
      <div>
        Bukti pembayaran sudah dikirim
        @if ($invoice->paid_at) pada {{ $invoice->paid_at->translatedFormat('d M Y H:i') }} @endif.
        <b>Menunggu konfirmasi admin.</b>
      </div>
    </div>

    @if ($invoice->payment_proof)
      <div class="card">
        <h2>Bukti yang dikirim</h2>
        <img class="proof" src="{{ asset('storage/' . $invoice->payment_proof) }}" alt="Bukti transfer tagihan #{{ $invoice->id }}">

        <form method="POST" action="{{ route('customer.invoice.upload-proof', $invoice) }}" enctype="multipart/form-data" class="card-actions">
          @csrf
          <details>
            <summary class="summary-link">Salah upload? Ganti bukti transfer</summary>
            <div class="details-body">
              <div class="field">
                <label for="bank_to_re">Rekening tujuan</label>
                <select id="bank_to_re" name="bank_to" required>
                  @foreach ($banks as $b)
                    <option value="{{ $b['key'] }}" @selected($invoice->bank_to === $b['key'])>{{ $b['bank'] }} · {{ $b['account'] }}</option>
                  @endforeach
                </select>
              </div>
              <div class="field">
                <label for="proof_re">Foto bukti baru</label>
                <input id="proof_re" name="payment_proof" type="file" accept="image/*" required />
              </div>
              <button class="btn btn-secondary btn-block" type="submit">Ganti bukti</button>
            </div>
          </details>
        </form>
      </div>
    @endif
  @endif

  @if ($invoice->status === InvoiceModel::STATUS_PAID)
    <div class="alert ok">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
      <div>
        Pembayaran sudah dikonfirmasi.
        @if ($invoice->extended_to)
          Akun aktif sampai <b>{{ $invoice->extended_to->translatedFormat('d M Y H:i') }}</b>.
        @endif
      </div>
    </div>
  @endif
@endsection

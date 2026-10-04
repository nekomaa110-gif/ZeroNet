<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>{{ $invoice->slug }} · ZeroNet</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
  <meta name="theme-color" content="#F2EFE8">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

  @php
    use App\Models\Invoice;
    $statusMap = [
      Invoice::STATUS_DRAFT     => ['draft', 'DIPROSES ADMIN'],
      Invoice::STATUS_UNPAID    => ['unpaid', 'MENUNGGU PEMBAYARAN'],
      Invoice::STATUS_PENDING   => ['pending', 'MENUNGGU KONFIRMASI'],
      Invoice::STATUS_PAID      => ['paid', 'LUNAS'],
      Invoice::STATUS_CANCELLED => ['cancelled', 'DIBATALKAN'],
    ];
    $st = $statusMap[$invoice->status] ?? ['draft', strtoupper($invoice->status)];

    $item = 'Perpanjangan Voucher';

    $portalUrl = (string) config('services.billing.portal_url');

    $imageMode = (bool) request()->query('image');
  @endphp

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: #ECE9DF; color: #1A1A1A; }
    body {
      font-family: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
      font-size: 14px; line-height: 1.5;
      min-height: 100vh; min-height: 100dvh;
      display: flex; justify-content: center; align-items: flex-start;
      padding: 20px 16px;
      -webkit-font-smoothing: antialiased;
    }
    body.image-mode { min-height: 0; padding: 16px 14px; }

    .receipt {
      background: #F2EFE8;
      width: 100%; max-width: 420px;
      padding: 32px 26px 28px;
      position: relative;
      box-shadow: 0 1px 0 rgba(0,0,0,.04), 0 12px 32px -8px rgba(0,0,0,.08);
    }

    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
    .brand { font-size: 18px; font-weight: 700; letter-spacing: -.01em; }
    .brand .tag { display: block; font-size: 11px; font-weight: 400; color: #5F5E57; margin-top: 2px; letter-spacing: .04em; white-space: nowrap; }
    .meta { text-align: right; flex-shrink: 0; }
    .meta .lbl { font-size: 10px; color: #5F5E57; letter-spacing: .12em; font-weight: 400; }
    .meta .val { font-size: 18px; font-weight: 700; margin-top: 2px; letter-spacing: .02em; white-space: nowrap; }
    .meta .sub { font-size: 11px; color: #5F5E57; margin-top: 2px; letter-spacing: .04em; white-space: nowrap; }

    .rule { height: 1px; background: #1A1A1A; margin: 22px 0 0; }
    .dash { border-top: 1px dashed #B8B5AC; margin: 16px 0; }

    .status { display: flex; align-items: center; gap: 10px; padding: 16px 0 0; font-size: 12px; letter-spacing: .14em; font-weight: 500; }
    .status .dot { width: 10px; height: 10px; border-radius: 999px; flex-shrink: 0; background: currentColor; }
    .st-draft { color: #5B645E; }
    .st-unpaid { color: #915B00; }
    .st-pending { color: #2C5E8C; }
    .st-paid { color: #1F7A45; }
    .st-cancelled { color: #B3261E; }
    .v.is-ok { color: #1F7A45; font-weight: 700; }
    .bank-block.spaced { margin-top: 18px; }
    .cta + .cta { margin-top: 8px; }

    .row { display: flex; justify-content: space-between; gap: 12px; margin: 10px 0; align-items: baseline; }
    .row .k { font-size: 11px; color: #5F5E57; letter-spacing: .12em; font-weight: 400; flex-shrink: 0; }
    .row .v { font-size: 13.5px; text-align: right; font-weight: 500; word-break: break-word; max-width: 64%; }

    .item { padding: 4px 0; }

    .total { display: flex; justify-content: space-between; align-items: baseline; padding: 18px 0 4px; gap: 12px; }
    .total .k { font-size: 11px; color: #5F5E57; letter-spacing: .14em; font-weight: 400; }
    .total .v { font-size: 32px; font-weight: 700; letter-spacing: -.02em; }

    .bank-block { padding-top: 4px; }
    .bank-block .lbl { font-size: 10px; letter-spacing: .14em; color: #5F5E57; font-weight: 400; margin-bottom: 8px; }
    .bank { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 8px 0; }
    .bank + .bank { border-top: 1px dashed #B8B5AC; }
    .bank .name { font-size: 14px; font-weight: 700; letter-spacing: -.01em; }
    .bank .holder { font-size: 11.5px; color: #5F5E57; margin-top: 2px; }
    .bank .acc { font-size: 13px; font-weight: 500; letter-spacing: .04em; font-variant-numeric: tabular-nums; text-align: right; }
    .bank .acc .copy {
      display: inline-block; margin-left: 8px;
      background: #1A1A1A; color: #F2EFE8;
      font-size: 10px; padding: 3px 8px; border-radius: 999px;
      cursor: pointer; user-select: none; border: 0; font-family: inherit;
      letter-spacing: .04em;
    }
    .image-mode .bank-block { display: none; }
    .image-mode .cta { display: none !important; }
    .image-mode .total + .rule { display: none; }

    .cta {
      display: block; width: 100%;
      background: #1A1A1A; color: #F2EFE8;
      text-align: center; padding: 14px;
      text-decoration: none; font-weight: 700;
      font-size: 13px; letter-spacing: .06em;
      margin-top: 22px;
      transition: opacity .12s;
    }
    .cta:active { opacity: .85; }
    .cta.ok { background: #1F7A45; }
    .cta.muted { background: transparent; color: #1A1A1A; border: 1px solid #1A1A1A; }

    .foot {
      margin-top: 28px;
      display: flex; justify-content: space-between; align-items: flex-end;
      font-size: 10px; color: #5F5E57; letter-spacing: .04em;
    }
    .foot .url { word-break: break-all; max-width: 70%; }

    .barcode {
      margin-top: 10px;
      display: flex; gap: 2px; align-items: center;
      height: 28px;
    }
    .barcode i {
      display: block; height: 100%;
      background: #1A1A1A;
    }
    .code {
      margin-top: 6px;
      font-size: 9.5px; color: #5F5E57;
      letter-spacing: .1em; word-break: break-all;
    }

    .note {
      margin-top: 16px;
      padding: 12px;
      background: rgba(31,122,69,.1);
      border-left: 3px solid #1F7A45;
      font-size: 12px; line-height: 1.6;
    }
    .note.warn { background: rgba(145,91,0,.1); border-left-color: #915B00; }
    .note.err { background: rgba(179,38,30,.1); border-left-color: #B3261E; }
    .note b { font-weight: 700; }

    @media (max-width: 380px) {
      .receipt { padding: 26px 22px 24px; }
      .total .v { font-size: 28px; }
    }
  </style>
</head>
<body class="{{ $imageMode ? 'image-mode' : '' }}">
  <div class="receipt">
    <div class="head">
      <div class="brand">
        {{ config('site.invoice_brand') }}
        <span class="tag">voucher bulanan</span>
      </div>
      <div class="meta">
        <div class="lbl">INVOIS</div>
        <div class="val">#{{ $invoice->id }}</div>
        <div class="sub">{{ optional($invoice->created_at)->translatedFormat('d M Y') }}</div>
      </div>
    </div>

    <div class="rule"></div>

    <div class="status st-{{ $st[0] }}">
      <span class="dot"></span>
      <span>{{ $st[1] }}</span>
    </div>

    <div class="dash"></div>

    <div class="row">
      <span class="k">AKUN</span>
      <span class="v">{{ $contact?->name ?: $invoice->username }}</span>
    </div>
    @if ($contact?->phone)
      <div class="row">
        <span class="k">NO. HP</span>
        <span class="v">{{ $contact->phone }}</span>
      </div>
    @endif
    @if ($invoice->due_date && !in_array($invoice->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED], true))
      <div class="row">
        <span class="k">JATUH TEMPO</span>
        <span class="v">{{ $invoice->due_date->translatedFormat('d M Y') }}</span>
      </div>
    @endif
    @if ($invoice->extended_to && $invoice->isPaid())
      <div class="row">
        <span class="k">AKTIF SAMPAI</span>
        <span class="v is-ok">{{ $invoice->extended_to->translatedFormat('d M Y') }}</span>
      </div>
    @endif

    <div class="dash"></div>

    <div class="row item">
      <span class="k">ITEM</span>
      <span class="v">{{ $item }}</span>
    </div>

    <div class="dash"></div>

    <div class="total">
      <span class="k">{{ $invoice->isPaid() ? 'TOTAL DIBAYAR' : 'TOTAL TAGIHAN' }}</span>
      <span class="v">
        @if ($invoice->amount > 0)
          Rp{{ number_format($invoice->amount, 0, ',', '.') }}
        @else
        @endif
      </span>
    </div>

    <div class="rule"></div>

    @if (in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING], true) && $invoice->amount > 0)
      <div class="bank-block spaced">
        <div class="lbl">TRANSFER KE</div>
        @foreach ($banks as $b)
          <div class="bank">
            <div>
              <div class="name">{{ $b['bank'] }}</div>
              <div class="holder">a.n. {{ $b['holder'] }}</div>
            </div>
            <div class="acc">
              {{ chunk_split($b['account'], 4, ' ') }}
              <button class="copy" type="button"
                      onclick="navigator.clipboard.writeText('{{ $b['account'] }}'); this.textContent='TERSALIN'; setTimeout(()=>this.textContent='SALIN',1500);">SALIN</button>
            </div>
          </div>
        @endforeach
      </div>
    @endif

    @if ($invoice->status === Invoice::STATUS_DRAFT)
      <div class="note warn">
        <b>Permintaan diterima.</b><br>
        Admin sedang menetapkan harga. Kamu akan dapat notifikasi WA saat tagihan siap dibayar.
      </div>
    @elseif ($invoice->status === Invoice::STATUS_PENDING)
      <div class="note">
        <b>Bukti pembayaran sudah diterima.</b><br>
        Menunggu konfirmasi admin. Akun akan diperpanjang otomatis setelah dikonfirmasi.
      </div>
    @elseif ($invoice->status === Invoice::STATUS_PAID)
      <div class="note">
        <b>✓ Pembayaran terkonfirmasi.</b><br>
        @if ($invoice->extended_to)
          Akun aktif sampai {{ $invoice->extended_to->translatedFormat('d M Y') }}.
        @endif
      </div>
    @elseif ($invoice->status === Invoice::STATUS_CANCELLED)
      <div class="note err">
        <b>Invoice dibatalkan.</b><br>
        Hubungi admin jika ini kekeliruan.
      </div>
    @endif

    @if ($invoice->status === Invoice::STATUS_UNPAID)
      <a href="{{ route('customer.invoice.show', $invoice) }}" class="cta">UPLOAD BUKTI TRANSFER →</a>
      <a href="https://wa.me/{{ $businessWa }}" class="cta muted">CHAT ADMIN</a>
    @elseif ($invoice->status === Invoice::STATUS_DRAFT)
      <a href="https://wa.me/{{ $businessWa }}" class="cta muted">CHAT ADMIN</a>
    @elseif ($invoice->status === Invoice::STATUS_PENDING)
      <a href="{{ route('customer.dashboard') }}" class="cta muted">BUKA DASHBOARD</a>
    @elseif ($invoice->status === Invoice::STATUS_PAID)
      <a href="{{ route('customer.dashboard') }}" class="cta ok">BUKA DASHBOARD →</a>
    @endif

    <div class="foot">
      <div class="url">{{ preg_replace('#^https?://#', '', $portalUrl) }}</div>
      <div>v.001</div>
    </div>

    <div class="barcode">
      @php
        $hash = md5($invoice->slug);
        $widths = [];
        for ($i = 0; $i < 30; $i++) {
          $byte = hexdec($hash[$i % 32]);
          $widths[] = ($byte % 4) + 1;
        }
      @endphp
      @foreach ($widths as $w)
        <i style="width: {{ $w }}px;"></i>
      @endforeach
    </div>
    <div class="code">{{ $invoice->slug }}</div>
  </div>
</body>
</html>

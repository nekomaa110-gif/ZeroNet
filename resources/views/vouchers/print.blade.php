<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Cetak {{ $batch->code }} · {{ config('app.name', 'ZeroNet') }}</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">

  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0; background: #E9ECEA; color: #141815;
      font-family: 'IBM Plex Sans', system-ui, -apple-system, sans-serif;
    }

    .bar {
      position: sticky; top: 0; z-index: 10;
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      padding: 12px 18px; background: #fff; border-bottom: 1px solid #D3D9D5;
    }
    .bar h1 { margin: 0; font-size: 15px; font-weight: 700; }
    .bar .sub { font-size: 12px; color: #5B645E; }
    .bar .spacer { flex: 1; }
    .bar a, .bar button, .bar select {
      font: inherit; font-size: 13px; padding: 7px 12px; border-radius: 8px;
      border: 1px solid #B7BFB9; background: #fff; color: #141815;
      text-decoration: none; cursor: pointer;
    }
    .bar .primary { background: #0A6B55; border-color: #0A6B55; color: #fff; font-weight: 600; }

    .sheet { padding: 10mm 8mm; }

    .cards {
      display: grid;
      grid-template-columns: repeat({{ $tpl['per_row'] }}, 1fr);
      gap: 3mm;
    }

    .v {
      border: 1px dashed #5B645E; border-radius: 3mm;
      padding: {{ $tpl['per_row'] >= 8 ? '2mm' : '3mm' }};
      background: #fff;
      break-inside: avoid; page-break-inside: avoid;
      text-align: center;
      line-height: 1.35;
    }
    .v .brand {
      font-weight: 700; letter-spacing: .02em; color: #0A6B55;
      font-size: {{ $tpl['per_row'] >= 8 ? '8px' : ($tpl['per_row'] >= 4 ? '11px' : '16px') }};
    }
    .v .code {
      font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 700;
      margin: {{ $tpl['per_row'] >= 8 ? '1mm 0' : '2mm 0' }};
      letter-spacing: .04em; word-break: break-all;
      font-size: {{ $tpl['per_row'] >= 10 ? '11px' : ($tpl['per_row'] >= 8 ? '13px' : ($tpl['per_row'] >= 4 ? '19px' : '30px')) }};
    }
    .v .pw {
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      font-size: {{ $tpl['per_row'] >= 8 ? '9px' : ($tpl['per_row'] >= 4 ? '12px' : '18px') }};
      color: #2B322E;
    }
    .v .meta {
      font-size: {{ $tpl['per_row'] >= 8 ? '7px' : ($tpl['per_row'] >= 4 ? '9.5px' : '13px') }};
      color: #434B46;
    }
    .v .note {
      margin-top: 1mm; font-size: {{ $tpl['per_row'] >= 8 ? '6.5px' : ($tpl['per_row'] >= 4 ? '8.5px' : '12px') }};
      color: #5B645E;
    }
    .v .label { color: #5B645E; }

    .kosong { padding: 40px; text-align: center; color: #5B645E; }

    @media print {
      @page { size: A4; margin: 8mm; }
      body { background: #fff; }
      .bar { display: none; }
      .sheet { padding: 0; }
      .v { border-color: #B7BFB9; }
    }
  </style>
</head>
<body>

  @include('vouchers._print-bar')

  <div class="sheet">
    @if ($vouchers->isEmpty())
      <div class="kosong">Tidak ada kartu yang cocok dengan pilihan cetak ini.</div>
    @else
      <div class="cards">
        @foreach ($vouchers as $v)
          <div class="v">
            <div class="brand">{{ $card['brand'] }}</div>

            @if ($v->username === $v->password)
              <div class="code">{{ $v->username }}</div>
            @else
              <div class="code">{{ $v->username }}</div>
              <div class="pw"><span class="label">pw</span> {{ $v->password }}</div>
            @endif

            <div class="meta">
              {{ $batch->profile }}
              @if ($v->price) · Rp {{ number_format($v->price, 0, ',', '.') }} @endif
            </div>

            <div class="meta">
              @if ($batch->limit_uptime) {{ $batch->limit_uptime }} pemakaian @endif
              @if ($batch->limit_uptime && $batch->validity) · @endif
              @if ($batch->validity) berlaku {{ $batch->validity }} @endif
            </div>

            <div class="note">{{ $card['login'] }}</div>
          </div>
        @endforeach
      </div>
    @endif
  </div>

  @include('vouchers._print-script')

</body>
</html>

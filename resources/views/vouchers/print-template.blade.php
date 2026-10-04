<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Cetak {{ $batch->code }} · {{ config('app.name', 'ZeroNet') }}</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">

  <style>
    body {
      color: #000; background-color: #fff; font-size: 14px;
      font-family: 'Helvetica', arial, sans-serif; margin: 0;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .bar {
      position: sticky; top: 0; z-index: 10;
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      padding: 12px 18px; background: #fff; border-bottom: 1px solid #D3D9D5;
      font-family: system-ui, -apple-system, sans-serif;
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
    .sheet { padding: 8px; }
    .kisi { display: grid; grid-template-columns: repeat({{ max(1, $perRow) }}, 1fr); gap: 3mm; }
    .kisi > div { break-inside: avoid; page-break-inside: avoid; }
    .kosong { padding: 40px; text-align: center; color: #5B645E; font-family: system-ui, sans-serif; }
    @page { size: auto; margin: 9mm 3mm 3mm 7mm; }
    @media print {
      .bar { display: none; }
      .sheet { padding: 0; }
      table { page-break-after: auto; }
      tr, td { page-break-inside: avoid; page-break-after: auto; }
    }
  </style>
  <style>{!! $gaya !!}</style>
</head>
<body>

  @include('vouchers._print-bar')

  <div class="sheet">
    @if ($kartu === [])
      <div class="kosong">Tidak ada kartu yang cocok dengan pilihan cetak ini.</div>
    @elseif ($perRow > 0)
      <div class="kisi">
        @foreach ($kartu as $k)
          <div>{!! $k !!}</div>
        @endforeach
      </div>
    @else
      @foreach ($kartu as $k){!! $k !!}@endforeach
    @endif
  </div>

  @include('vouchers._print-script')

</body>
</html>

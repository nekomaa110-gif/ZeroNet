<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Voucher {{ $user['username'] }} ({{ config('site.brand') }})</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
      background: #ECE9DF; color: #1A1A1A;
      -webkit-font-smoothing: antialiased;
      padding: 24px 16px;
    }

    .bar {
      max-width: 100mm; margin: 0 auto 16px;
      display: flex; gap: 8px;
    }
    .bar button, .bar a {
      flex: 1; padding: 9px 12px; border: 1px solid #1A1A1A; background: #1A1A1A; color: #F2EFE8;
      font: inherit; font-size: 12.5px; font-weight: 700; text-align: center; text-decoration: none;
      cursor: pointer; border-radius: 4px;
    }
    .bar a { background: transparent; color: #1A1A1A; }

    @include('user-hotspot._kartu-voucher-css')

    @page { size: A4; margin: 10mm; }

    @media print {
      body { background: #FFFFFF; padding: 0; }
      .bar { display: none; }
      .voucher { margin: 0; border-color: #000; }
    }
  </style>
</head>
<body>

  <div class="bar">
    <button type="button" onclick="window.print()">Cetak</button>
    <a href="#" onclick="window.close(); return false;">Tutup</a>
  </div>

  @include('user-hotspot._kartu-voucher')

  <script>
    window.addEventListener('load', function () { window.print(); });
  </script>

</body>
</html>

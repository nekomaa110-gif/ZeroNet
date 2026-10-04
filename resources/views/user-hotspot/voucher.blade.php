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

    .voucher {
      width: 100mm; margin: 0 auto;
      background: #FFFFFF;
      border: 1.5px dashed #1A1A1A;
      padding: 14px 16px 12px;
    }

    .brand {
      font-size: 17px; font-weight: 700; letter-spacing: -.02em;
      text-align: center; line-height: 1.2;
    }
    .tagline {
      font-size: 9px; text-align: center; color: #5A5A5A;
      margin-top: 3px; letter-spacing: .04em; text-transform: uppercase;
    }
    .rule { border-top: 1px solid #1A1A1A; margin: 12px 0; }

    .fields { display: flex; align-items: stretch; gap: 16px; }
    .field { flex: 1 1 0; min-width: 0; }
    .field + .field { border-left: 1px dashed #9A9A9A; padding-left: 16px; }
    .field .k {
      font-size: 9.5px; letter-spacing: .12em; text-transform: uppercase; color: #5A5A5A;
    }
    .field .v {
      font-size: 20px; font-weight: 700; letter-spacing: .01em;
      word-break: break-all; line-height: 1.25; margin-top: 2px;
    }

    .foot {
      margin-top: 13px; padding-top: 9px; border-top: 1px dashed #9A9A9A;
      font-size: 9px; color: #5A5A5A; line-height: 1.5; text-align: center;
    }

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

  <div class="voucher">
    <div class="brand">{{ config('site.brand') }}</div>
    <div class="tagline">{{ config('site.tagline') }}</div>

    <div class="rule"></div>

    <div class="fields">
      <div class="field">
        <div class="k">Username</div>
        <div class="v">{{ $user['username'] }}</div>
      </div>

      <div class="field">
        <div class="k">Password</div>
        <div class="v">{{ $user['password'] }}</div>
      </div>
    </div>

    <div class="foot">
      Simpan kartu ini. Jangan dibagikan ke orang lain.
    </div>
  </div>

  <script>
    window.addEventListener('load', function () { window.print(); });
  </script>

</body>
</html>

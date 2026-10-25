<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=402" />
  <title>Voucher</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, Consolas, monospace;
      background: #FFFFFF; color: #1A1A1A;
      -webkit-font-smoothing: antialiased;
      padding: 12px;
    }

    @include('user-hotspot._kartu-voucher-css')
  </style>
</head>
<body>

  @include('user-hotspot._kartu-voucher')

</body>
</html>

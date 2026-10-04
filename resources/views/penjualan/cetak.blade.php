@php
  $rp = fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.');
  $angka = fn ($n) => number_format((int) $n, 0, ',', '.');
  $tgl = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('j M Y');
  $rentang = $filter['dari'] === $filter['sampai'] ? $tgl($filter['dari']) : $tgl($filter['dari']) . ' sampai ' . $tgl($filter['sampai']);
  $saringan = collect([
      $router?->name ?? 'Semua router',
      $filter['profile'] ? 'profil ' . $filter['profile'] : null,
      $filter['prefix'] ? 'awalan ' . $filter['prefix'] : null,
  ])->filter()->implode(' · ');
  $adaJual = $total['transaksi_harga_jual'] > 0;
@endphp
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Laporan Penjualan {{ $rentang }} · {{ config('app.name', 'ZeroNet') }}</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
  <style>
    :root { --ink: #141815; --ink-2: #434B46; --ink-3: #5B645E; --line: #D3D9D5; --brand: #0A6B55; }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: #fff; font: 12px/1.45 'IBM Plex Sans', 'Segoe UI', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bar { position: sticky; top: 0; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 18px; background: #fff; border-bottom: 1px solid var(--line); font-family: system-ui, sans-serif; }
    .bar .spacer { flex: 1; }
    .bar a, .bar button { font: inherit; font-size: 13px; padding: 7px 12px; border-radius: 6px; border: 1px solid #B7BFB9; background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
    .bar .primary { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 600; }
    .lembar { max-width: 190mm; margin: 0 auto; padding: 18px 16px 32px; }
    header.kop { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; padding-bottom: 10px; border-bottom: 2px solid var(--ink); }
    header.kop h1 { margin: 0; font-size: 18px; }
    header.kop p { margin: 2px 0 0; color: var(--ink-2); }
    header.kop .kanan { text-align: right; color: var(--ink-3); font-size: 11px; }
    .angka { display: grid; grid-template-columns: repeat({{ $adaJual ? 4 : 2 }}, 1fr); margin: 14px 0 6px; border: 1px solid var(--line); }
    .angka > div { padding: 8px 10px; border-left: 1px solid var(--line); }
    .angka > div:first-child { border-left: 0; }
    .angka dt { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3); }
    .angka dd { margin: 2px 0 0; font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; }
    h2 { margin: 18px 0 6px; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 4px 6px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-3); border-bottom: 1px solid var(--ink-2); }
    td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    tfoot td { font-weight: 700; border-top: 1px solid var(--ink-2); border-bottom: 0; }
    .mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11px; }
    .catatan { margin-top: 10px; color: var(--ink-3); font-size: 11px; }
    .kosong { padding: 24px 0; text-align: center; color: var(--ink-3); }
    @page { size: A4; margin: 12mm 10mm; }
    @media print {
      .bar { display: none; }
      .lembar { max-width: none; padding: 0; }
      thead { display: table-header-group; }
      tr { page-break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <b>Laporan penjualan</b>
    <span>{{ $rentang }} · {{ $saringan }}</span>
    <span class="spacer"></span>
    <a href="{{ route('penjualan.index', request()->query()) }}">Kembali</a>
    <button type="button" class="primary" onclick="window.print()">Cetak / Simpan PDF</button>
  </div>

  <main class="lembar">
    <header class="kop">
      <div>
        <h1>Laporan Penjualan Voucher</h1>
        <p>{{ $rentang }} · {{ $saringan }}</p>
      </div>
      <div class="kanan">{{ config('app.name', 'ZeroNet') }}<br>dicetak {{ now()->locale('id')->translatedFormat('j M Y H:i') }}</div>
    </header>

    <dl class="angka">
      <div><dt>Transaksi</dt><dd>{{ $angka($total['transaksi']) }}</dd></div>
      <div><dt>Omzet</dt><dd>{{ $rp($total['omzet']) }}</dd></div>
      @if ($adaJual)
        <div><dt>Harga jual</dt><dd>{{ $rp($total['harga_jual']) }}</dd></div>
        <div><dt>Margin</dt><dd>{{ $rp($total['margin']) }}</dd></div>
      @endif
    </dl>
    @if ($adaJual && $total['transaksi_harga_jual'] < $total['transaksi'])
      <p class="catatan">Harga jual diketahui untuk {{ $angka($total['transaksi_harga_jual']) }} dari {{ $angka($total['transaksi']) }} transaksi.</p>
    @endif

    @if ($total['transaksi'] === 0)
      <p class="kosong">Tidak ada penjualan pada rentang dan saringan ini.</p>
    @else
      <h2>Per router</h2>
      <table>
        <thead><tr><th>Router</th><th class="n">Transaksi</th><th class="n">Omzet</th>@if ($adaJual)<th class="n">Margin</th>@endif</tr></thead>
        <tbody>
          @foreach ($perRouter as $b)
            <tr><td>{{ $b['router_name'] }}</td><td class="n">{{ $angka($b['transaksi']) }}</td><td class="n">{{ $rp($b['omzet']) }}</td>@if ($adaJual)<td class="n">{{ $rp($b['margin']) }}</td>@endif</tr>
          @endforeach
        </tbody>
      </table>

      <h2>Per profil</h2>
      <table>
        <thead><tr><th>Router</th><th>Profil</th><th class="n">Transaksi</th><th class="n">Omzet</th></tr></thead>
        <tbody>
          @foreach ($perProfil as $b)
            <tr><td>{{ $b['router_name'] }}</td><td>{{ $b['profile'] ?: '-' }}</td><td class="n">{{ $angka($b['transaksi']) }}</td><td class="n">{{ $rp($b['omzet']) }}</td></tr>
          @endforeach
        </tbody>
      </table>

      @if ($perHari)
        <h2>Per hari</h2>
        <table>
          <thead><tr><th>Tanggal</th><th class="n">Transaksi</th><th class="n">Omzet</th></tr></thead>
          <tbody>
            @foreach ($perHari as $b)
              <tr><td>{{ $tgl($b['tanggal']) }}</td><td class="n">{{ $angka($b['transaksi']) }}</td><td class="n">{{ $rp($b['omzet']) }}</td></tr>
            @endforeach
          </tbody>
        </table>
      @endif

      <h2>Transaksi</h2>
      @if ($jumlah > $maks)
        <p class="catatan">{{ $angka($jumlah) }} transaksi, terlalu banyak untuk dicetak (batas {{ $angka($maks) }}). Persempit rentang atau pakai Unduh CSV untuk daftar lengkap.</p>
      @else
        <table>
          <thead><tr><th>Waktu</th><th>Router</th><th>Voucher</th><th>Profil</th><th class="n">Harga</th></tr></thead>
          <tbody>
            @foreach ($transaksi as $t)
              <tr>
                <td class="mono">{{ \Illuminate\Support\Carbon::parse($t->sold_at)->format('d/m H:i') }}</td>
                <td>{{ $t->router_name }}</td>
                <td class="mono">{{ $t->username }}</td>
                <td>{{ $t->profile }}</td>
                <td class="n">{{ $t->price !== null ? $angka($t->price) : '-' }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot><tr><td colspan="4">Jumlah</td><td class="n">{{ $angka($total['omzet']) }}</td></tr></tfoot>
        </table>
      @endif
    @endif
  </main>
</body>
</html>

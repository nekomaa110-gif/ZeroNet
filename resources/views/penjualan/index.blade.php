@extends('layouts.app')

@section('title', 'Penjualan Voucher')
@section('page-title', 'Penjualan')
@section('page-class', 'pg-penjualan')

@section('content')

  @php
    $rp        = fn ($n) => 'Rp ' . number_format((int) $n, 0, ',', '.');
    $tgl       = fn ($d) => \Illuminate\Support\Carbon::parse($d)->locale('id')->translatedFormat('d M Y');
    $basi      = $routers->filter(fn ($r) => ! $r->last_sales_pull_at || $r->last_sales_pull_at->lt(now()->subMinutes(30)));
    $saring    = array_filter(['router' => $router?->slug, 'profile' => $filter['profile'], 'prefix' => $filter['prefix'], 'dari' => $filter['dari'], 'sampai' => $filter['sampai']]);
  @endphp

  <header class="page-head">
    <div>
      <h2>Penjualan voucher</h2>
      <p>Dari record penjualan di router, {{ $filter['dari'] === $filter['sampai'] ? $tgl($filter['dari']) : $tgl($filter['dari']) . ' sampai ' . $tgl($filter['sampai']) }}.</p>
    </div>
  </header>

  <section class="readout pj-readout" aria-label="Ringkasan penjualan">
    <div>
      <div class="rd-label">Transaksi</div>
      <div class="rd-value tnum">{{ number_format($total['transaksi'], 0, ',', '.') }}</div>
    </div>
    <div>
      <div class="rd-label">Pendapatan {{ $label }}</div>
      <div class="rd-value tnum">{{ $rp($total['omzet']) }}</div>
    </div>
    <div>
      <div class="rd-label">Omzet bulan ini</div>
      <div class="rd-value tnum">{{ $rp($bulanIni['omzet']) }}</div>
    </div>
    <div>
      <div class="rd-label">Margin</div>
      @if ($total['transaksi_harga_jual'] === 0)
        <div class="rd-value pj-kosong" title="Butuh harga jual di profil">-</div>
      @else
        <div class="rd-value tnum {{ $total['margin'] < 0 ? 'is-err' : '' }}">{{ $rp($total['margin']) }}</div>
      @endif
    </div>
  </section>

  @if ($basi->isNotEmpty() || $ragu > 0)
    <div class="pj-notes">
      @if ($basi->isNotEmpty())
        <x-admin.alert type="warning" :message="'Data penjualan belum segar untuk ' . $basi->map(fn ($r) => $r->name . ' (' . ($r->last_sales_pull_at ? 'terakhir ' . $r->last_sales_pull_at->locale('id')->diffForHumans() : 'belum pernah ditarik') . ')')->implode(', ') . '. Angka di bawah bisa kurang dari penjualan sebenarnya.'"/>
      @endif
      @if ($ragu > 0)
        <x-admin.alert type="info" :message="number_format($ragu, 0, ',', '.') . ' transaksi punya waktu yang tidak masuk akal (jam router salah) dan tidak dihitung di laporan ini.'"/>
      @endif
    </div>
  @endif

  <div class="section-bar">
    <h3>Record penjualan</h3>
    <span class="t-mute">{{ number_format($transaksi->total(), 0, ',', '.') }} record, terbaru di atas</span>
    <div class="pj-ekspor push-end">
      <a href="{{ route('penjualan.unduh', $saring) }}" class="btn btn-ghost btn-sm">Unduh CSV</a>
      <a href="{{ route('penjualan.cetak', $saring) }}" class="btn btn-ghost btn-sm" target="_blank" rel="noopener" data-page-transition="none">Cetak / PDF</a>
    </div>
  </div>

  <div class="card">
    <form class="toolbar pj-filter" method="GET" action="{{ route('penjualan.index') }}">
      <select name="router" class="select tb-select" data-live-submit aria-label="Router">
        <option value="">Semua router</option>
        @foreach ($routers as $r)
          <option value="{{ $r->slug }}" @selected($router && $router->id === $r->id)>{{ $r->name }}</option>
        @endforeach
      </select>
      <select name="profile" class="select tb-select" data-live-submit aria-label="Profil">
        <option value="">Semua profil</option>
        @foreach ($profiles as $p)
          <option value="{{ $p }}" @selected($filter['profile'] === $p)>{{ $p }}</option>
        @endforeach
      </select>
      <input type="text" name="prefix" value="{{ $filter['prefix'] }}" class="input pj-prefix mono" placeholder="Prefix kode" maxlength="20" pattern="[A-Za-z0-9]*" aria-label="Prefix username" data-live-submit>
      <label class="pj-tgl"><span class="t-mute">Dari</span><input type="date" name="dari" value="{{ $isian['dari'] }}" class="input" aria-label="Dari tanggal" data-live-submit></label>
      <label class="pj-tgl"><span class="t-mute">Sampai</span><input type="date" name="sampai" value="{{ $isian['sampai'] }}" class="input" aria-label="Sampai tanggal" data-live-submit></label>
    </form>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Waktu</th>
            <th class="pj-opt">Router</th>
            <th>Kode</th>
            <th class="pj-opt">Profil</th>
            <th class="ta-r">Harga</th>
            <th class="pj-opt ta-r">Harga jual</th>
            <th class="pj-opt pj-lebar">MAC</th>
            <th class="pj-opt pj-lebar">Batch</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($transaksi as $t)
            <tr>
              <td class="mono nowrap">{{ \Illuminate\Support\Carbon::parse($t->sold_at)->format('d/m H:i') }}</td>
              <td class="pj-opt nowrap">{{ $t->router_name ?? '-' }}</td>
              <td><div class="mono t-strong">{{ $t->username }}</div><div class="pj-sub">{{ $t->router_name ?? '-' }} · {{ $t->profile ?? '-' }}</div></td>
              <td class="pj-opt">{{ $t->profile ?? '-' }}</td>
              <td class="mono ta-r nowrap">{{ $t->price !== null ? $rp($t->price) : '-' }}</td>
              <td class="pj-opt mono ta-r nowrap">
                @if ($t->sprice !== null) {{ $rp($t->sprice) }} @else <span class="t-mute">belum diisi</span> @endif
              </td>
              <td class="pj-opt pj-lebar mono t-mute">{{ $t->mac ?? '-' }}</td>
              <td class="pj-opt pj-lebar t-mute">{{ $t->batch_comment ?? '-' }}</td>
            </tr>
          @empty
            <tr>
              <td class="empty-cell" colspan="8">
                <div class="empty-inner">
                  <div class="t-strong">Tidak ada penjualan pada rentang ini</div>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if ($transaksi->hasPages())
      <div class="card-foot">{{ $transaksi->links() }}</div>
    @endif
  </div>

@endsection

@extends('layouts.app')

@section('title', 'Log User Hotspot')
@section('page-title', 'Log User Hotspot')

@section('page-class', 'pg-hotspot-logs-router')

@section('content')

  @php
    $adaSaring = (bool) ($router || $filter['cari'] || ($filter['kejadian'] ?? null) || ($filter['dari'] ?? null) || ($filter['sampai'] ?? null));
    $label = ['masuk' => ['Masuk', 'ok'], 'keluar' => ['Keluar', ''], 'gagal' => ['Gagal', 'err'], 'mencoba' => ['Mencoba', 'info'], 'lain' => ['Lain', '']];
  @endphp

  <header class="page-head">
    <div>
      <h2>Log User Hotspot</h2>
      <p>Log hotspot dari router, termasuk voucher dan pelanggan RADIUS. Disimpan {{ \App\Services\RouterLogService::SIMPAN_HARI }} hari.</p>
    </div>
    <div class="head-actions">
      <div class="seg" role="group" aria-label="Sumber log">
        <a href="{{ route('hotspot-logs.index') }}" aria-pressed="false">RADIUS</a>
        <a href="{{ route('hotspot-logs.index', ['sumber' => 'router']) }}" class="active" aria-pressed="true">Router</a>
      </div>
    </div>
  </header>

  <div class="card" x-data="logRouterBaru(@js(route('hotspot-logs.tonton')), {{ $terbaru }})">
    <form class="toolbar" method="GET" action="{{ route('hotspot-logs.index') }}">
      <input type="hidden" name="sumber" value="router">
      <select name="router" class="select tb-select" data-live-submit aria-label="Router">
        <option value="">Semua router</option>
        @foreach ($routers as $r)
          <option value="{{ $r->slug }}" @selected($router && $router->id === $r->id)>{{ $r->name }}</option>
        @endforeach
      </select>
      <input type="text" name="cari" value="{{ $filter['cari'] }}" class="input lh-cari" placeholder="Cari user atau IP" maxlength="64" data-live-submit>
      <select name="kejadian" class="select tb-select" data-live-submit aria-label="Kejadian">
        <option value="">Semua kejadian</option>
        @foreach ($label as $k => [$teks])
          <option value="{{ $k }}" @selected(($filter['kejadian'] ?? null) === $k)>{{ $teks }}</option>
        @endforeach
      </select>
      <input type="date" name="dari" value="{{ $filter['dari'] ?? '' }}" class="input w-auto" data-live-submit title="Dari tanggal">
      <input type="date" name="sampai" value="{{ $filter['sampai'] ?? '' }}" class="input w-auto" data-live-submit title="Sampai tanggal">
      <div class="tb-end">
        <a x-show="baru > 0" x-cloak href="{{ url()->full() }}" class="btn btn-sm" x-text="baru + ' log baru, muat ulang'"></a>
        @if ($adaSaring)
          <a href="{{ route('hotspot-logs.index', ['sumber' => 'router']) }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif
      </div>
    </form>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Waktu</th>
            <th class="lh-opt">Router</th>
            <th>User</th>
            <th class="lh-opt">IP</th>
            <th>Kejadian</th>
            <th class="lh-opt">Keterangan</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($logs as $l)
            <tr>
              <td class="mono nowrap">{{ $l->waktu->format('d/m H:i:s') }}</td>
              <td class="lh-opt nowrap">{{ $l->router?->name ?? '-' }}</td>
              <td>
                <div class="mono t-strong">{{ $l->pengguna ?? '-' }}</div>
                <div class="lh-sub">{{ $l->router?->name ?? '-' }} · {{ $l->ip ?? '-' }} · {{ $l->pesan }}</div>
              </td>
              <td class="lh-opt mono t-mute">{{ $l->ip ?? '-' }}</td>
              <td><span class="badge {{ $label[$l->kejadian][1] ?? '' }}">{{ $label[$l->kejadian][0] ?? $l->kejadian }}</span></td>
              <td class="lh-opt t-mute">{{ $l->pesan }}</td>
            </tr>
          @empty
            <tr>
              <td class="empty-cell" colspan="6">
                <div class="empty-inner">
                  <div class="t-strong">{{ $adaSaring ? 'Tidak ada log yang cocok' : 'Belum ada log dari router' }}</div>
                  @if ($adaSaring)
                    <a class="link-strong" href="{{ route('hotspot-logs.index', ['sumber' => 'router']) }}">Reset filter →</a>
                  @else
                    <div class="t-mute">Poller membaca log router tiap 5 menit, tiap 30 detik selama halaman ini terbuka.</div>
                  @endif
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if ($logs->hasPages())
      <div class="card-foot">{{ $logs->links() }}</div>
    @endif
  </div>

@endsection

@push('scripts')
<script>
function logRouterBaru(url, terbaru) {
  return {
    baru: 0,
    init() {
      const tonton = async () => {
        if (document.hidden) return;
        try {
          const res = await fetch(url, { headers: { Accept: 'application/json' } });
          const data = await res.json();
          if (data.terbaru > terbaru) this.baru = data.terbaru - terbaru;
        } catch (e) {}
      };
      tonton();
      setInterval(tonton, 20000);
    },
  };
}
</script>
@endpush

@extends('layouts.app')

@section('title', 'Log User Hotspot')
@section('page-title', 'Log User Hotspot')

@section('page-class', 'pg-hotspot-logs-index')

@section('content')

  @php
    $hasFilter = (bool)($search || $status || $dateFrom || $dateTo || $showRaw);
  @endphp

  <header class="page-head">
    <div>
      <h2>Log User Hotspot</h2>
      <p>Riwayat login user hotspot dari RADIUS (pelanggan bulanan).</p>
    </div>
    <div class="head-actions">
      <div class="seg" role="group" aria-label="Sumber log">
        <a href="{{ route('hotspot-logs.index') }}" class="active" aria-pressed="true">RADIUS</a>
        <a href="{{ route('hotspot-logs.index', ['sumber' => 'router']) }}" aria-pressed="false">Router</a>
      </div>
      <a href="{{ url()->full() }}" class="btn btn-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </a>
    </div>
  </header>

  <div class="card">

    <div style="padding: 14px var(--pad-card); border-bottom: 1px solid var(--border);">
      <form class="row-wrap" method="GET" action="{{ route('hotspot-logs.index') }}"
           >

        <div class="input-group" style="width:220px">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="search" value="{{ $search }}" placeholder="Cari username..." data-live-search class="input">
        </div>

        <input type="date" name="date_from" value="{{ $dateFrom }}" data-live-submit class="input w-auto" title="Dari tanggal">
        <span class="t-mute">-</span>
        <input type="date" name="date_to" value="{{ $dateTo }}" data-live-submit class="input w-auto" title="Sampai tanggal">

        <select name="status" data-live-submit class="select" style="width:auto;min-width:140px">
          <option value="">Semua Status</option>
          <option value="success" @selected($status === 'success')>Berhasil</option>
          <option value="failed"  @selected($status === 'failed')>Gagal</option>
        </select>

        <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-2);cursor:pointer" title="Tampilkan retry duplikat & reject yang akhirnya berhasil">
          <input type="checkbox" name="show_raw" value="1" @checked($showRaw) data-live-submit>
          Tampilkan semua attempt
        </label>

        @if($hasFilter)
          <a href="{{ route('hotspot-logs.index') }}" class="btn btn-ghost btn-sm">Reset</a>
        @endif
      </form>
    </div>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th class="ix">#</th>
            <th>Username</th>
            <th>Tanggal</th>
            <th>Jam</th>
            <th class="ta-c">Status</th>
            <th>Keterangan</th>
            <th>NAS / IP Klien</th>
          </tr>
        </thead>
        <tbody>
          @forelse($logs as $log)
            @include('hotspot-logs._row', [
              'log'           => $log,
              'rejectReasons' => $rejectReasons,
              'userIps'       => $userIps,
              'rowNumber'     => $logs->firstItem() + $loop->index,
              'isNew'         => false,
            ])
          @empty
            <tr>
              <td class="empty-cell" colspan="7">
                <div class="empty-inner">
                  <div class="empty-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                  </div>
                  <div class="t-strong">
                    @if($hasFilter) Tidak ada log yang cocok @else Belum ada log autentikasi @endif
                  </div>
                  @if($hasFilter)
                    <a class="link-strong" href="{{ route('hotspot-logs.index') }}">Reset filter →</a>
                  @endif
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if($logs->hasPages())
      <div class="card-foot">{{ $logs->withQueryString()->links() }}</div>
    @endif
  </div>

@endsection

@push('scripts')
@endpush

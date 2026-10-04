@extends('layouts.app')

@section('title', 'VPN L2TP')
@section('page-title', 'VPN L2TP')

@section('content')

  <header class="page-head">
    <div>
      <h2>VPN L2TP</h2>
      <p>Akun remote akses MikroTik per lokasi/divisi.</p>
    </div>
    <div class="head-actions">
      <button type="button" class="btn btn-primary" data-l2tp-add>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah Akun
      </button>
    </div>
  </header>

  <div class="card card-pad" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:24px;font-size:13px">
    <div><span class="label-caps">Server L2TP</span><b class="mono">{{ $localIp }} : 1701</b></div>
    <div><span class="label-caps">Range IP tunnel</span><b class="mono">{{ $pool['start'] }} – {{ $pool['end'] }}</b></div>
    <div><span class="label-caps">IP tersedia</span><b class="mono">{{ count($pool['free']) }} alamat</b></div>
    @if (count($pool['reserved']))
      <div><span class="label-caps">Dipesan (jangan dipakai)</span>
        @foreach ($pool['reserved'] as $ip => $note)
          <b class="mono" title="{{ $note }}">{{ $ip }}</b>{{ ! $loop->last ? ', ' : '' }}
        @endforeach
      </div>
    @endif
  </div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th class="ix">#</th>
          <th>Username</th>
          <th>IP Tunnel</th>
          <th>Catatan</th>
          <th>Status</th>
          <th class="ta-r">Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($accounts as $account)
          <tr>
            <td class="ix">{{ $loop->iteration }}</td>
            <td><b class="mono">{{ $account->username }}</b></td>
            <td class="mono">{{ $account->remote_ip }}</td>
            <td>{{ $account->note ?: '' }}</td>
            <td>
              @if ($account->is_active)
                <span class="badge ok">Aktif</span>
              @else
                <span class="badge warn">Nonaktif</span>
              @endif
            </td>
            <td style="text-align:right;white-space:nowrap">
              <button type="button" class="btn btn-sm" data-l2tp-toggle
                      data-id="{{ $account->id }}" data-active="{{ $account->is_active ? '1' : '0' }}"
                      data-name="{{ $account->username }}">
                {{ $account->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
              </button>
              <button type="button" class="btn btn-sm" data-l2tp-edit
                      data-id="{{ $account->id }}"
                      data-username="{{ $account->username }}"
                      data-ip="{{ $account->remote_ip }}"
                      data-note="{{ $account->note }}">
                Edit
              </button>
              <button type="button" class="btn btn-sm btn-danger" data-l2tp-delete
                      data-id="{{ $account->id }}" data-name="{{ $account->username }}">
                Hapus
              </button>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" style="text-align:center;padding:44px 20px">
              <div style="font-weight:600;margin-bottom:4px">Belum ada akun L2TP</div>
              <div style="color:var(--text-3);font-size:13px;margin-bottom:16px">
                Tambahkan akun pertama, akan langsung ditulis ke server (chap-secrets) dan xl2tpd di-restart otomatis.
              </div>
              <button type="button" class="btn btn-primary" data-l2tp-add>Tambah Akun</button>
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

@endsection

@push('overlays')
  @include('l2tp._form-modal')
@endpush

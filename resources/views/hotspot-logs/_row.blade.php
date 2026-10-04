@php
    $success  = $log->isSuccess();
    $nasIp    = $log->nasipaddress ?? '';
    if ($nasIp === 'localhost') $nasIp = '127.0.0.1';
    $clientIp = $userIps[$log->username] ?? '';
    $isNew    = $isNew ?? false;
@endphp
<tr class="{{ $isNew ? 'log-row-new' : '' }}">
  <td class="ix">
    @if($isNew)
      <span class="badge ok" style="padding:1px 6px;font-size:10px">baru</span>
    @else
      {{ $rowNumber ?? '' }}
    @endif
  </td>

  <td class="fw-6">{{ $log->username }}</td>

  <td style="font-size:12px;color:var(--text-2);white-space:nowrap">
    {{ $log->authdate?->format('d M Y') }}
  </td>

  <td class="mono hint">
    {{ $log->authdate?->format('H:i:s') }}
  </td>

  <td class="ta-c">
    @if($success)
      <span class="badge ok">Berhasil</span>
    @else
      <span class="badge err">Gagal</span>
    @endif
  </td>

  <td class="fs-12">
    @if($success)
      <span class="t-ok">Login berhasil</span>
    @else
      @php $reason = $rejectReasons[$log->username] ?? 'Access-Reject'; @endphp
      @if($reason === 'Voucher sudah expired')
        <span class="t-warn">{{ $reason }}</span>
      @elseif($reason === 'Akun dinonaktifkan')
        <span class="t-warn">{{ $reason }}</span>
      @elseif($reason === 'Akun sedang digunakan')
        <span style="color:var(--info)">{{ $reason }}</span>
      @else
        <span class="t-err">{{ $reason }}</span>
      @endif
    @endif
  </td>

  <td class="fs-12">
    @if($nasIp !== '' || $clientIp !== '')
      <div style="display:flex;flex-direction:column;gap:4px;">
        @if($nasIp !== '')
          <div style="display:flex;align-items:center;gap:6px">
            <span style="font-size:10px;font-weight:600;width:30px;text-align:center;padding:1px 0;border-radius:4px;background:var(--bg-mute);color:var(--text-3);letter-spacing:.04em">NAS</span>
            <span class="mono muted">{{ $nasIp }}</span>
          </div>
        @endif
        @if($clientIp !== '')
          <div style="display:flex;align-items:center;gap:6px">
            <span style="font-size:10px;font-weight:600;width:30px;text-align:center;padding:1px 0;border-radius:4px;background:color-mix(in srgb, var(--ok) 14%, transparent);color:var(--ok);letter-spacing:.04em">IP</span>
            <span class="mono t-ok">{{ $clientIp }}</span>
          </div>
        @endif
      </div>
    @else
      <span class="t-mute"></span>
    @endif
  </td>
</tr>

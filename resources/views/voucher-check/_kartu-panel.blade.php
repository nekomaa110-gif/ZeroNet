@php
  $k = $p['kartu'] ?? null;
  $d = $k && $k['sumber'] === 'terhapus' ? $k['model'] : null;
@endphp

<div class="card mb-4 vc-panel">
  <div class="card-head">
    <h3>Kartu di panel</h3>
    @if($k)
      <div class="ch-actions">
        <span class="badge {{ $d ? 'err' : 'info' }} no-dot">{{ $d ? 'Sudah dihapus' : 'Ada di panel' }}</span>
      </div>
    @endif
  </div>

  <div class="card-pad">
    @if(! $k)
      <div class="muted">Tidak ada di panel maupun riwayat.</div>
    @else
      <div class="grid-2 gap-wide">
        <div>
          <div class="kvp">
            <span class="k">Batch</span>
            <span class="v mono">
              @if(! $d && $k['batch_id'])
                <a href="{{ route('vouchers.show', $k['batch_id']) }}">{{ $k['batch_code'] }}</a>
              @else
                {{ $k['batch_code'] ?? '-' }}
              @endif
            </span>
          </div>
          <div class="kvp"><span class="k">Router</span><span class="v">{{ $k['router_name'] ?? '-' }}</span></div>
          <div class="kvp">
            <span class="k">No</span>
            <span class="v mono">
              {{ $k['nomor'] ?? '-' }}
              @if($k['nomor'] === null)<small class="muted">nomor kartu batch ini belum dikunci</small>@endif
            </span>
          </div>
          <div class="kvp"><span class="k">Username</span><span class="v mono">{{ $k['username'] }}</span></div>
        </div>

        <div>
          @if($d)
            <div class="kvp"><span class="k">Riwayat</span><span class="v t-err">Sudah dihapus {{ $d->deleted_at->format('d M Y H:i') }} ({{ $d->cara_label }}) oleh {{ $d->penghapus?->name ?? $d->penghapus?->username ?? '-' }}</span></div>
            <div class="kvp"><span class="k">Status saat dihapus</span><span class="v">{{ $d->status_label }}</span></div>
            <div class="kvp"><span class="k">Hasil cabut</span><span class="v">{{ $d->hasil_router_label }}</span></div>
          @else
            <div class="kvp"><span class="k">Status panel</span><span class="v"><span class="badge {{ $k['model']->status_tone }} no-dot">{{ $k['status_label'] }}</span></span></div>
          @endif
        </div>
      </div>
    @endif

    @if($p['peringatan'] ?? [])
      <ul class="vc-peringatan">
        @foreach($p['peringatan'] as $w)<li>{{ $w }}</li>@endforeach
      </ul>
    @endif
  </div>
</div>

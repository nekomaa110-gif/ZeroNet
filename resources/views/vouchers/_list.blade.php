@if ($vouchers->isEmpty())
  <div class="vl-kosong">Tidak ada kartu yang cocok dengan filter ini.</div>
@else
  @php $bolehHapus = auth()->user()?->isAdmin(); @endphp
  @if ($bolehHapus)
    <div hidden data-daftar-info data-total="{{ $vouchers->total() }}" data-rincian="{{ json_encode($rincian ?? []) }}"
         data-status="{{ $filters['status'] ?? '' }}" data-sync="{{ $filters['sync'] ?? '' }}" data-q="{{ $filters['q'] ?? '' }}"></div>
    <label class="vl-pilih-hp"><input type="checkbox" data-pilih-semua> Pilih semua di halaman ini</label>
    <div class="vl-massal" data-massal hidden>
      <span class="vl-massal-teks" data-massal-teks></span>
      <button type="button" class="btn btn-ghost btn-sm" data-massal-semua hidden></button>
      <span class="vl-massal-aksi">
        <button type="button" class="btn btn-ghost btn-sm" data-massal-batal>Batal</button>
        <button type="button" class="btn btn-sm vb-btn-bahaya" data-massal-hapus>Hapus</button>
      </span>
    </div>
  @endif
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          @if ($bolehHapus)
            <th class="vl-pilih"><input type="checkbox" data-pilih-semua aria-label="Pilih semua kartu di halaman ini"></th>
          @endif
          <th class="ix">#</th>
          <th>Kode</th>
          <th>Password</th>
          <th>Status</th>
          <th>Dipakai sejak</th>
          <th>Kedaluwarsa</th>
          <th>Router</th>
          @if ($bolehHapus)
            <th class="vl-aksi"><span class="sr-only">Aksi</span></th>
          @endif
        </tr>
      </thead>
      <tbody>
        @foreach ($vouchers as $v)
          <tr>
            @if ($bolehHapus)
              <td class="vl-pilih"><input type="checkbox" data-pilih value="{{ $v->id }}" data-status="{{ $v->status_efektif }}" aria-label="Pilih kartu {{ $v->username }}"></td>
            @endif
            <td class="ix">{{ $loop->iteration + ($vouchers->currentPage() - 1) * $vouchers->perPage() }}</td>
            <td class="mono fw-6 vl-kode">{{ $v->username }}</td>
            <td class="mono vl-pass">@if ($v->username !== $v->password)<span class="vl-lbl">pass </span>{{ $v->password }}@endif</td>
            <td class="vl-status">
              <span class="badge {{ $v->status_tone }}">{{ $v->status_label }}</span>
              @if ($v->uptime_used > 0)
                <div class="vl-terpakai">terpakai {{ app(\App\Services\VoucherLookupService::class)->durasi($v->uptime_used) }}</div>
              @endif
            </td>
            <td class="fs-125 vl-mulai">@if ($v->activated_at)<span class="vl-lbl">dipakai </span>{{ $v->activated_at->format('d M Y H:i') }}@endif</td>
            <td class="fs-125 vl-akhir">@if ($v->expired_at)<span class="vl-lbl">habis </span>{{ $v->expired_at->format('d M Y H:i') }}@endif</td>
            <td class="vl-router">
              @if ($v->sync_status === 'success')
                <span class="badge ok no-dot" title="Sudah ada di router">ada</span>
              @elseif ($v->sync_status === 'failed')
                <span class="badge err" title="{{ $v->sync_error }}">gagal</span>
              @elseif ($v->sync_status === 'kirim')
                <span class="badge warn" title="Sudah dikirim, balasan router belum diterima. Dicek ulang saat sinkron berikutnya.">dikirim</span>
              @else
                <span class="badge">belum</span>
              @endif
            </td>
            @if ($bolehHapus)
              <td class="vl-aksi">
                <div class="tbl-actions">
                  <button type="button" class="icon-btn t-err" title="Hapus kartu ini"
                          data-hapus-kartu="{{ route('vouchers.destroy-one', $v) }}"
                          data-kode="{{ $v->username }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                  </button>
                </div>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  @if ($vouchers->hasPages())
    <div class="card-foot">{{ $vouchers->withQueryString()->links() }}</div>
  @endif
@endif

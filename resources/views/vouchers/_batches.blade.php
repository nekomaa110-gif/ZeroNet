@if ($batches->isEmpty())
  <div class="empty-cell">
    <div class="empty-inner">
      <div class="empty-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/></svg>
      </div>
      <div class="t-strong">Belum ada batch voucher.</div>
      <div class="fs-125">Tekan <b>Buat Voucher</b> untuk mencetak batch pertama.</div>
    </div>
  </div>
@else
  @php $bolehHapus = auth()->user()?->isAdmin(); @endphp
  @if ($bolehHapus)
    <label class="vl-pilih-hp"><input type="checkbox" data-pilih-semua> Pilih semua di halaman ini</label>
    <div class="vl-massal" data-massal hidden>
      <span class="vl-massal-teks" data-massal-teks></span>
      <span class="vl-massal-aksi">
        <button type="button" class="btn btn-ghost btn-sm" data-massal-batal>Batal</button>
        <button type="button" class="btn btn-sm vb-btn-bahaya" data-massal-hapus="panel">Hapus dari panel</button>
        <button type="button" class="btn btn-sm vb-btn-bahaya" data-massal-hapus="router">Hapus + cabut dari router</button>
      </span>
    </div>
  @endif
  <div class="tbl-wrap">
    <table class="tbl">
      <thead>
        <tr>
          @if ($bolehHapus)
            <th class="vl-pilih"><input type="checkbox" data-pilih-semua aria-label="Pilih semua batch di halaman ini"></th>
          @endif
          <th>Batch</th>
          <th>Router / Profil</th>
          <th class="num">Jumlah</th>
          <th>Sinkronisasi</th>
          <th class="vb-lebar">Dibuat</th>
          <th class="vb-aksi"><span class="sr-only">Aksi</span></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($batches as $b)
          <tr>
            @if ($bolehHapus)
              @php $pakai = ($pemakaian ?? collect())->get($b->id); @endphp
              <td class="vl-pilih">
                <input type="checkbox" data-pilih value="{{ $b->id }}" data-kode="{{ $b->code }}" data-router="{{ $b->router_label }}"
                       data-kartu="{{ (int) $b->quantity }}" data-siap="{{ (int) ($pakai->siap ?? 0) }}" data-aktif="{{ (int) ($pakai->aktif ?? 0) }}"
                       data-hapus="{{ route('vouchers.destroy', $b) }}" aria-label="Pilih batch {{ $b->code }}"
                       @if ($b->sedangSinkron()) disabled title="Batch sedang sinkron ke router" @endif>
              </td>
            @endif
            <td class="vb-kode">
              <a class="t-strong" href="{{ route('vouchers.show', $b) }}">
                <span class="mono">{{ $b->code }}</span>
              </a>
              @if ($b->label)
                <div class="hint">{{ $b->label }}</div>
              @endif
              <div class="hint vb-sub">{{ $b->created_at?->format('d M Y H:i') }}</div>
            </td>
            <td class="vb-router">
              {{ $b->router_label }}
              <div class="hint">
                {{ $b->profile }}
                @if ($b->validity) · masa {{ $b->validity }} @endif
                @if ($b->limit_uptime) · jatah {{ $b->limit_uptime }} @endif
              </div>
            </td>
            <td class="num vb-jumlah">{{ number_format($b->quantity, 0, ',', '.') }}<span class="vb-unit"> kartu</span></td>
            <td class="vb-sync">
              <span class="badge {{ $b->status_tone }}">{{ $b->status_label }}</span>
              @if ($b->status !== 'success')
                <div class="progress {{ $b->status === 'failed' ? 'err' : ($b->status === 'partial' ? 'warn' : '') }}">
                  <i style="width:{{ $b->progress }}%"></i>
                </div>
                <div class="vb-sync-info">
                  {{ number_format($b->synced_count, 0, ',', '.') }}/{{ number_format($b->quantity, 0, ',', '.') }} masuk router
                  @if ($b->failed_count > 0) · {{ $b->failed_count }} gagal @endif
                </div>
              @endif
            </td>
            <td class="vb-lebar">
              <div class="fs-125">{{ $b->created_at?->format('d M Y H:i') }}</div>
              <div class="hint">{{ $b->creator?->name ?? $b->creator?->username ?? '' }}</div>
            </td>
            <td class="vb-aksi">
              <div class="tbl-actions">
                <a href="{{ route('vouchers.print', $b) }}" target="_blank" class="icon-btn" title="Cetak kartu" data-page-transition="none">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                </a>
                <a href="{{ route('vouchers.show', $b) }}" class="icon-btn" title="Lihat kartu">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  @if ($batches->hasPages())
    <div class="card-foot">{{ $batches->withQueryString()->links() }}</div>
  @endif
@endif

@if (! empty($ringkasanLive))
  <template data-live-oob="vc-ringkasan">@include('vouchers._ringkasan')</template>
@endif

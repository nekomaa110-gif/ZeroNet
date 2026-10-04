@php
  use App\Models\Invoice;
  $statusBadge = [
    Invoice::STATUS_DRAFT     => ['yellow', 'Diproses'],
    Invoice::STATUS_UNPAID    => ['yellow', 'Belum Bayar'],
    Invoice::STATUS_PENDING   => ['blue',   'Menunggu Konfirmasi'],
    Invoice::STATUS_PAID      => ['green',  'Lunas'],
    Invoice::STATUS_CANCELLED => ['red',    'Dibatalkan'],
  ];
@endphp

<div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th class="hide-sm">No.</th>
        <th>Pelanggan</th>
        <th class="hide-md">Profile</th>
        <th class="num">Total</th>
        <th>Status</th>
        <th class="hide-md">Jatuh tempo</th>
        <th class="hide-md">Dibuat</th>
        <th class="hide-md"><span class="sr-only">Aksi</span></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($invoices as $inv)
        @php
          $b = $statusBadge[$inv->status] ?? ['gray', $inv->status];
          $overdueDays = ($inv->due_date && in_array($inv->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID], true) && $inv->due_date->copy()->startOfDay()->lt(today()))
              ? (int) $inv->due_date->copy()->startOfDay()->diffInDays(today())
              : 0;
        @endphp
        <tr data-href="{{ route('billing.show', $inv) }}" tabindex="0" aria-label="Tagihan #{{ $inv->id }} {{ $inv->username }}">
          <td class="ix hide-sm">#{{ $inv->id }}</td>
          <td class="t-strong">{{ $inv->username }}</td>
          <td class="hide-md muted">{{ $inv->profile }}</td>
          <td class="num">
            @if ($inv->amount > 0)
              <span class="t-strong nowrap">Rp {{ number_format($inv->amount, 0, ',', '.') }}</span>
            @endif
          </td>
          <td><x-admin.badge :color="$b[0]" :dot="true">{{ $b[1] }}</x-admin.badge></td>
          <td class="hide-md nowrap {{ $overdueDays ? 't-err' : 'muted' }}">
            {{ optional($inv->due_date)->translatedFormat('d M Y') }}
            @if ($overdueDays)
              <div class="exp-remain">lewat {{ $overdueDays }} hari</div>
            @endif
          </td>
          <td class="hide-md mono t-mute nowrap">{{ optional($inv->created_at)->translatedFormat('d M, H:i') }}</td>
          <td class="hide-md num">
            <a class="btn btn-sm btn-ghost" href="{{ route('billing.show', $inv) }}">Detail</a>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="8">
            <div class="empty">
              <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></div>
              <div class="empty-title">Belum ada tagihan</div>
              @if (request('search') || request('status'))
                <div>Coba ubah filter atau pencarian.</div>
              @endif
            </div>
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

@if ($invoices->hasPages())
  <div class="tbl-foot">
    <span>Menampilkan <b>{{ $invoices->firstItem() }}-{{ $invoices->lastItem() }}</b> dari <b>{{ $invoices->total() }}</b> tagihan</span>
    <div>{{ $invoices->links() }}</div>
  </div>
@endif

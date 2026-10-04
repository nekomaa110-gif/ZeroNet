@php
  $badge = $inv->customerStatusBadge();
  $label = ($packageNames ?? [])[$inv->profile] ?? $inv->profile;
@endphp
<a class="inv-item" href="{{ route('customer.invoice.show', $inv) }}">
  <div class="inv-main">
    <div class="inv-title"><span class="mono">#{{ $inv->id }}</span> {{ $label }}</div>
    <div class="meta">{{ optional($inv->created_at)->translatedFormat('d M Y') }}</div>
    @if ($inv->paid_at)
      <div class="meta">
        Dibayar {{ $inv->paid_at->translatedFormat('d M Y') }}@if ($inv->bankLabel()), {{ $inv->bankLabel() }}@endif
      </div>
    @endif
  </div>
  <div class="inv-side">
    @if ($inv->amount > 0)
      <div class="amount">Rp {{ number_format($inv->amount, 0, ',', '.') }}</div>
    @endif
    <span class="status {{ $badge[0] }}"><span class="dot"></span>{{ $badge[1] }}</span>
  </div>
</a>

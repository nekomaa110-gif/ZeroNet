@php
  $angka = fn ($n) => number_format($n, 0, ',', '.');
@endphp
<section class="readout vc-readout" id="vc-ringkasan" aria-label="Ringkasan voucher">
  <div>
    <div class="rd-label">Total voucher</div>
    <div class="rd-value tnum">{{ $angka($stats['total']) }}</div>
  </div>
  <div>
    <div class="rd-label">Belum dipakai</div>
    <div class="rd-value tnum">{{ $angka($stats['ready']) }}</div>
  </div>
  <div>
    <div class="rd-label">Terjual</div>
    <div class="rd-value tnum">{{ $angka($stats['terjual']) }}</div>
  </div>
  <div>
    <div class="rd-label">Belum masuk router</div>
    <div class="rd-value tnum {{ $stats['gagal'] > 0 ? 'is-warn' : '' }}">{{ $angka($stats['gagal']) }}</div>
  </div>
</section>

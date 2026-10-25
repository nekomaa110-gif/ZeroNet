@php
  $b     = $h['batch'];
  $r     = $h['ringkas'];
  $angka = fn ($n) => number_format((int) $n, 0, ',', '.');
  $sel   = ['total' => 'Total', 'belum' => 'Belum dipakai', 'aktif' => 'Aktif', 'habis' => 'Habis', 'dihapus' => 'Dihapus', 'bermasalah' => 'Bermasalah'];
  $catat = [...(! $h['bernomor'] ? ['Nomor kartu batch ini belum dikunci.'] : []), ...$h['peringatan'], ...$h['peringatan_ujung']];
@endphp

<div class="card mb-4 vc-rentang">
  <div class="card-head" style="align-items:flex-start">
    <div>
      <h3 class="mono">{{ $b['kode'] ?? '-' }}</h3>
      <p class="sub-note">{{ $b['label'] ? $b['label'] . ' · ' : '' }}{{ $b['router'] }}</p>
    </div>
    @if($b['tautan'])
      <div class="ch-actions"><a class="btn btn-sm" href="{{ $b['tautan'] }}">Lihat batch</a></div>
    @endif
  </div>

  <div class="card-pad grid-2 gap-wide" style="padding-top:0">
    <div>
      <div class="kvp"><span class="k">Profil</span><span class="v">{{ $b['profil'] ?? '-' }}</span></div>
      <div class="kvp"><span class="k">Harga</span><span class="v">{{ $b['harga'] ? 'Rp ' . $angka($b['harga']) : '-' }}</span></div>
      <div class="kvp"><span class="k">Masa aktif</span><span class="v">{{ $b['validity'] }}</span></div>
    </div>
    <div>
      <div class="kvp"><span class="k">Dibuat</span><span class="v">{{ $b['dibuat'] }}</span></div>
      <div class="kvp"><span class="k">Pembuat</span><span class="v">{{ $b['pembuat'] }}</span></div>
      <div class="kvp"><span class="k">Jumlah kartu batch</span><span class="v">{{ $angka($b['jumlah']) }}</span></div>
    </div>
  </div>

  @if($catat)
    <div class="card-pad" style="padding-top:0">
      <ul class="vc-peringatan">
        @foreach($catat as $w)<li>{{ $w }}</li>@endforeach
      </ul>
    </div>
  @endif
</div>

<section class="readout mb-4" aria-label="Ringkasan rentang">
  @foreach($sel as $kunci => $label)
    <div>
      <div class="rd-label">{{ $label }}</div>
      <div class="rd-value tnum {{ $kunci === 'bermasalah' && $r[$kunci] > 0 ? 'is-warn' : '' }}">{{ $angka($r[$kunci]) }}</div>
    </div>
  @endforeach
</section>

<div class="card mb-4">
  <div class="tbl-wrap vc-tabel-rentang">
    <table class="tbl">
      <thead>
        <tr>
          <th class="ix">No</th><th>Username</th><th>Password</th><th>Status</th><th>Di router</th>
          <th>Login pertama</th><th>Habis pada</th><th>Terpakai</th><th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        @foreach($h['baris'] as $baris)
          <tr>
            <td class="ix">{{ $baris['nomor'] ?? '-' }}</td>
            <td class="mono">{{ $baris['username'] }}</td>
            <td class="mono">{{ $baris['password'] }}</td>
            <td class="vc-bungkus">
              <span class="badge {{ $baris['tone'] }} no-dot">{{ $baris['label'] }}</span>
              @if($baris['online'])<span class="badge ok">Online</span>@endif
            </td>
            <td class="vc-bungkus">{{ $baris['di_router'] }}</td>
            <td>{{ $baris['login_pertama'] ?? '-' }}</td>
            <td>{{ $baris['habis_pada'] ?? '-' }}</td>
            <td class="mono">{{ $baris['terpakai'] }}</td>
            <td class="vc-ket">
              @foreach($baris['keterangan'] as $ket)<div>{{ $ket }}</div>@endforeach
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

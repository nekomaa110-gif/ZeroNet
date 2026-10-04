@php
  $tone = fn (string $level) => match ($level) {
      'ok'    => 'ok',
      'warn'  => 'warn',
      default => 'err',
  };
@endphp

@foreach($r['gagal'] as $g)
  <div class="card card-pad verdict verdict-warn mb-4">
    <b>{{ $g['nama'] }} tidak bisa dihubungi</b>
    <div class="muted fs-13 mt-1">
      {{ $g['host'] }} ({{ $g['pesan'] }})<br>
      Kalau kartunya berasal dari lokasi ini, hasil di bawah belum lengkap.
    </div>
  </div>
@endforeach

@if($r['mode'] === 'password')
  <div class="card">
    <div class="card-head"><h3>Hasil pencarian password {{ $r['pass'] }}</h3></div>
    @if(! $r['cocok'])
      <div class="card-pad muted">Tidak ada user dengan password itu.</div>
    @else
      <div class="tbl-wrap">
        <table class="tbl">
          <thead><tr><th>Username</th><th>Router</th><th>Profile</th><th>Limit</th><th>Terpakai</th><th>Comment</th><th></th></tr></thead>
          <tbody>
            @foreach($r['cocok'] as $c)
              <tr>
                <td class="mono"><b>{{ $c['nama'] }}</b></td>
                <td>{{ $c['router'] }}</td>
                <td>{{ $c['profile'] }}</td>
                <td class="mono">{{ $c['limit'] }}</td>
                <td class="mono">{{ $c['uptime'] }}</td>
                <td class="hint">{{ $c['comment'] ?: '' }}</td>
                <td><a class="btn btn-sm" href="{{ route('voucher-check.index', ['kode' => $c['nama']]) }}">Periksa</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endif

@foreach($r['hasil'] as $b)
  <div class="card verdict verdict-{{ $tone($b['vonis']['level']) }} mb-4">
    <div class="card-head" style="align-items:flex-start">
      <div>
        <h3 class="verdict-title"><span class="led {{ $tone($b['vonis']['level']) }}" aria-hidden="true"></span>{{ $b['vonis']['teks'] }}</h3>
        <p class="sub-note">
          {{ $b['router']['nama'] }} · {{ $b['router']['host'] }} · jam router {{ $b['jam_teks'] }}
        </p>
      </div>
      @if($b['user'])
        <div class="ch-actions">
          <span class="badge {{ $b['vonis']['level'] }}">{{ $b['user']['profile'] }}</span>
        </div>
      @endif
    </div>

    @if($b['vonis']['catatan'])
      <div class="card-pad" style="padding-top:0;color:var(--text-2);font-size:13px">
        @foreach($b['vonis']['catatan'] as $c)<div>{{ $c }}</div>@endforeach
      </div>
    @endif

    <div class="card-pad grid-2 gap-wide">
      @if($u = $b['user'])
        <div>
          <div class="nav-section pl-0">Data di router</div>
          <div class="kvp"><span class="k">Username</span><span class="v mono">{{ $u['nama'] }}</span></div>
          <div class="kvp">
            <span class="k">Password</span>
            <span class="v mono">
              {{ $u['password'] ?: '(kosong)' }}
              @if($u['pass_cocok'] === true)  <span class="badge ok no-dot">cocok</span>
              @elseif($u['pass_cocok'] === false) <span class="badge err no-dot">beda dari kartu</span>
              @endif
            </span>
          </div>
          <div class="kvp"><span class="k">Router / divisi</span><span class="v">{{ $b['router']['nama'] }}</span></div>
          <div class="kvp"><span class="k">Hotspot server</span><span class="v">{{ $u['server'] }}</span></div>
          <div class="kvp"><span class="k">Profile</span><span class="v">{{ $u['profile'] }} <small style="color:var(--text-2);font-weight:400">{{ $u['profil_info'] }}</small></span></div>
          <div class="kvp"><span class="k">Pemakaian data</span><span class="v">{{ $u['data_teks'] }}</span></div>
          @if($u['mac_kunci'])
            <div class="kvp"><span class="k">Terkunci ke MAC</span><span class="v mono">{{ $u['mac_kunci'] }}</span></div>
          @endif
          <div class="kvp"><span class="k">Comment</span><span class="v mono fs-12">{{ $u['comment'] ?: '(kosong)' }}</span></div>
        </div>

        <div>
          <div class="nav-section pl-0">Kuota &amp; masa aktif</div>
          <div style="padding:9px 0">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px">
              <span class="muted">Jatah jam</span>
              <span class="fw-6">{{ $u['kuota_teks'] }}</span>
            </div>
            <div class="progress {{ $u['bar_level'] }}">
              <i style="width:{{ $u['persen'] }}%"></i>
            </div>
          </div>
          <div class="kvp"><span class="k">Batas hangus</span><span class="v">{{ $b['masa']['exp_teks'] }}</span></div>
          @if($b['masa']['sisa_teks'])
            <div class="kvp">
              <span class="k">{{ $b['masa']['exp'] ? 'Sisa masa aktif' : 'Artinya' }}</span>
              <span class="v" style="color:{{ $b['masa']['lewat'] ? 'var(--err)' : 'var(--ok)' }}">{{ $b['masa']['sisa_teks'] }}</span>
            </div>
          @endif
        </div>
      @endif

      @if($rec = $b['record'])
        <div style="{{ $b['user'] ? 'grid-column:1/-1;border-top:1px solid var(--border);margin-top:10px;padding-top:6px' : '' }}">
          <div class="nav-section pl-0">Bukti pemakaian dan catatan penjualan</div>
          <div class="grid-2 gap-wide">
            <div>
              <div class="kvp"><span class="k">Login pertama</span><span class="v">{{ $rec['waktu_teks'] }}</span></div>
              <div class="kvp"><span class="k">IP saat login</span><span class="v mono">{{ $rec['ip'] ?: '' }}</span></div>
              <div class="kvp"><span class="k">MAC perangkat</span><span class="v mono">{{ $rec['mac'] ?: '' }}</span></div>
              <div class="kvp"><span class="k">Harga</span><span class="v">{{ $rec['harga_teks'] }}</span></div>
            </div>
            <div>
              <div class="kvp"><span class="k">Masa aktif dijual</span><span class="v">{{ $rec['masa'] ?: '' }}</span></div>
              <div class="kvp"><span class="k">Profile saat jual</span><span class="v">{{ $rec['profile'] ?: '' }}</span></div>
              <div class="kvp"><span class="k">Batch asal</span><span class="v mono fs-12">{{ $rec['batch'] ?: '' }}</span></div>
              @if(! $b['user'])
                <div class="kvp"><span class="k">Kesimpulan</span><span class="v t-err">sudah laku &amp; sudah dipakai</span></div>
              @endif
            </div>
          </div>

          @if(count($b['records']) > 1)
            <div style="margin-top:8px;font-size:12px;color:var(--text-2)">
              Ada {{ count($b['records']) }} catatan untuk kode ini (pernah dipakai lebih dari sekali):
              @foreach($b['records'] as $r2)
                <div class="mono">· {{ $r2['waktu_teks'] }} · IP {{ $r2['ip'] }} · MAC {{ $r2['mac'] }}</div>
              @endforeach
            </div>
          @endif
        </div>
      @elseif($b['user'])
        <div style="grid-column:1/-1;border-top:1px solid var(--border);margin-top:10px;padding-top:10px;color:var(--text-2);font-size:13px">
          Belum ada catatan penjualan (kode ini memang belum pernah dipakai login).
        </div>
      @endif

      @if($b['sesi'])
        <div style="grid-column:1/-1;border-top:1px solid var(--border);margin-top:10px;padding-top:6px">
          <div class="nav-section pl-0">Sesi aktif sekarang <span class="live" style="margin-left:6px">online</span></div>
          @foreach($b['sesi'] as $a)
            <div class="grid-2 gap-wide">
              <div>
                <div class="kvp"><span class="k">IP</span><span class="v mono">{{ $a['address'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">MAC</span><span class="v mono">{{ $a['mac-address'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">Login via</span><span class="v">{{ $a['login-by'] ?? '' }}</span></div>
              </div>
              <div>
                <div class="kvp"><span class="k">Lama sesi</span><span class="v mono">{{ $a['uptime'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">Sisa jatah sesi</span><span class="v mono">{{ $a['session-time-left'] ?? '(tanpa batas)' }}</span></div>
                <div class="kvp"><span class="k">Idle</span><span class="v mono">{{ $a['idle-time'] ?? '' }}</span></div>
              </div>
            </div>
          @endforeach
        </div>
      @endif

      @if($b['mac'])
        <div style="grid-column:1/-1;border-top:1px solid var(--border);margin-top:10px;padding-top:6px">
          <div class="nav-section pl-0">Perangkat / host: <span class="mono">{{ $b['mac'] }}</span></div>
          @forelse($b['hosts'] as $h)
            <div class="grid-2 gap-wide">
              <div>
                <div class="kvp"><span class="k">IP didapat</span><span class="v mono">{{ $h['address'] ?? '' }}{{ ($h['to-address'] ?? '') ? '  →  '.$h['to-address'] : '' }}</span></div>
                <div class="kvp"><span class="k">Server</span><span class="v">{{ $h['server'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">Status</span><span class="v">{{ ($h['authorized'] ?? '') === 'true' ? 'sudah login' : 'baru nyantol, belum login' }}</span></div>
              </div>
              <div>
                <div class="kvp"><span class="k">Lama nyantol</span><span class="v mono">{{ $h['uptime'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">Idle</span><span class="v mono">{{ $h['idle-time'] ?? '' }}</span></div>
                <div class="kvp"><span class="k">Trafik host</span><span class="v">{{ $h['trafik'] }}</span></div>
              </div>
            </div>
          @empty
            <div style="color:var(--text-2);font-size:13px;padding:6px 0">
              Perangkat ini sudah tidak terdaftar di <span class="mono">/ip hotspot host</span> (sudah pergi dari jaringan).
            </div>
          @endforelse

          @foreach($b['cookies'] as $c)
            <div class="kvp">
              <span class="k">Mac-cookie</span>
              <span class="v">ada, kedaluwarsa dalam {{ $c['expires-in'] ?? '?' }} <small style="font-weight:400;color:var(--text-2)">(bisa login otomatis tanpa ketik kode)</small></span>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </div>
@endforeach

@if($rad = $r['radius'])
  <div class="card verdict verdict-{{ $tone($rad['vonis']['level']) }} mb-4">
    <div class="card-head">
      <div>
        <h3 class="verdict-title"><span class="led {{ $tone($rad['vonis']['level']) }}" aria-hidden="true"></span>{{ $rad['vonis']['teks'] }}</h3>
        <p class="sub-note">
          Ini user RADIUS, bukan voucher lokal. Kelola lewat menu User Hotspot.
        </p>
      </div>
      <div class="ch-actions">
        <a class="btn btn-sm" href="{{ route('user-hotspot.index', ['search' => $rad['username']]) }}">Buka di User Hotspot</a>
      </div>
    </div>

    <div class="card-pad grid-2 gap-wide">
      <div>
        <div class="nav-section pl-0">Data radcheck</div>
        <div class="kvp"><span class="k">Username</span><span class="v mono">{{ $rad['username'] }}</span></div>
        <div class="kvp"><span class="k">Paket / group</span><span class="v">{{ $rad['group'] }}</span></div>
        <div class="kvp"><span class="k">Expiration</span><span class="v">{{ $rad['exp_teks'] }}</span></div>
        <div class="kvp"><span class="k">Sisa masa aktif</span><span class="v">{{ $rad['sisa_teks'] }}</span></div>
        <div class="kvp"><span class="k">Status blokir</span><span class="v">{{ $rad['diblokir'] ? 'diblokir (Auth-Type := Reject)' : 'tidak diblokir' }}</span></div>
      </div>

      @if($s = $rad['sesi'])
        <div>
          <div class="nav-section pl-0">Sesi terakhir (radacct)</div>
          <div class="kvp"><span class="k">Mulai</span><span class="v">{{ $s->acctstarttime }}</span></div>
          <div class="kvp"><span class="k">Berhenti</span><span class="v">{{ $s->acctstoptime ?: 'masih berjalan' }}</span></div>
          <div class="kvp"><span class="k">IP</span><span class="v mono">{{ $s->framedipaddress }}</span></div>
          <div class="kvp"><span class="k">MAC</span><span class="v mono">{{ $s->callingstationid }}</span></div>
          <div class="kvp"><span class="k">NAS / router</span><span class="v mono">{{ $s->nasipaddress }}</span></div>
        </div>
      @endif
    </div>
  </div>
@endif

@if(! $r['ketemu'])
  <div class="card card-pad verdict verdict-err">
    <h3 style="color:var(--err);margin:0">TIDAK PERNAH ADA</h3>
    <p style="color:var(--text-2);font-size:13px;margin:6px 0 0">
      Kode <span class="mono">{{ $r['kode'] }}</span> tidak ada di daftar user, catatan penjualan, maupun radcheck.
      Kemungkinan salah baca kartu, atau kartunya dari lokasi lain yang belum ikut diperiksa.
    </p>

    @if($r['mirip'])
      <div style="margin-top:14px">
        <div class="nav-section pl-0">Nama yang beda satu huruf (cocokkan password-nya dengan kartu)</div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead><tr><th>Username</th><th>Password</th><th>Router</th><th>Profile</th><th>Terpakai</th><th></th></tr></thead>
            <tbody>
              @foreach($r['mirip'] as $m)
                <tr>
                  <td class="mono"><b>{{ $m['nama'] }}</b></td>
                  <td class="mono">{{ $m['pass'] }}</td>
                  <td>{{ $m['slug'] }}</td>
                  <td>{{ $m['profile'] }}</td>
                  <td class="mono">{{ $m['uptime'] }}</td>
                  <td><a class="btn btn-sm" href="{{ route('voucher-check.index', ['kode' => $m['nama']]) }}">Periksa</a></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    @endif
  </div>
@endif

@if($riw = $r['riwayat'])
  <div class="card">
    <div class="card-head">
      <div>
        <h3>Riwayat perangkat <span class="mono">{{ $riw['mac'] }}</span></h3>
        <p class="sub-note">
          Tercatat memakai <b>{{ $riw['total'] }} voucher</b> di {{ $riw['router'] }}.
        </p>
      </div>
    </div>
    <div class="tbl-wrap">
      <table class="tbl">
        <thead><tr><th>Waktu login</th><th>Voucher</th><th>Harga</th><th>IP</th><th>Batch</th></tr></thead>
        <tbody>
          @foreach(array_reverse($riw['items']) as $it)
            <tr @if($it['ini']) style="background:color-mix(in srgb, var(--warn) 10%, transparent)" @endif>
              <td class="mono">{{ $it['waktu_teks'] }}</td>
              <td class="mono"><b>{{ $it['user'] }}</b> @if($it['ini'])<span class="badge warn no-dot">yang dicek</span>@endif</td>
              <td>{{ $it['harga_teks'] }}</td>
              <td class="mono">{{ $it['ip'] }}</td>
              <td class="hint">{{ $it['batch'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

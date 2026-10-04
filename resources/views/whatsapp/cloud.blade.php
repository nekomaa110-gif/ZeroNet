@extends('layouts.app')

@section('title', 'WhatsApp Cloud API')
@section('page-title', 'WhatsApp Cloud API')


@section('page-class', 'pg-whatsapp-cloud')

@section('content')

  @php
    $envReady = $appId !== '' && $configId !== '' && $hasSecret;
    $connected = $hasToken && $phoneId !== '';
    $tone = $connected ? 'ok' : ($envReady ? 'warn' : 'err');
  @endphp

  <header class="page-head">
    <div>
      <h2>WhatsApp Cloud API</h2>
      <p>Sambungkan nomor bisnis ke jalur resmi Meta tanpa mencabutnya dari aplikasi WhatsApp Business di HP.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('whatsapp.index') }}" class="btn btn-sm">Kembali ke Gateway</a>
    </div>
  </header>

  <div style="display:flex;flex-direction:column;gap:16px;">

    <div class="card card-pad" style="display:flex;align-items:center;gap:12px;border-color:color-mix(in srgb, var({{ '--' . $tone }}) 30%, var(--border))">
      <div style="width:40px;height:40px;border-radius:10px;background:color-mix(in srgb,var({{ '--' . $tone }}) 18%,transparent);color:var({{ '--' . $tone }});display:grid;place-items:center;flex-shrink:0">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
      </div>
      <div class="grow min0">
        <b>
          @if($connected)
            Tersambung
          @elseif($envReady)
            Siap disambungkan
          @else
            Kredensial app belum diisi
          @endif
        </b>
        <div class="sub-note">
          @if($connected)
            Phone Number ID <span class="mono">{{ $phoneId }}</span>
          @elseif($envReady)
            App sudah dikenali, tinggal jalankan penyambungan di langkah 2
          @else
            Isi <span class="mono">META_APP_ID</span>, <span class="mono">META_APP_SECRET</span>, dan <span class="mono">META_ES_CONFIG_ID</span> di .env
          @endif
        </div>
      </div>
      @if($connected)
        <button type="button" class="btn btn-sm" id="wcVerify" style="flex-shrink:0">Tes kredensial</button>
      @endif
    </div>

    <div class="note-inline wc-warn">
      <b class="t-warn">Aturan mati selama penyambungan.</b>
      Kalau di layar mana pun muncul peringatan bahwa nomor akan dihapus dari aplikasi WhatsApp Business
      atau riwayat chat akan hilang. <b>Batalkan, jangan diteruskan</b>. Itu tandanya flow yang jalan bukan
      Coexistence. Setelah aktif: buka aplikasi WhatsApp Business minimal sekali tiap 13 hari, dan
      jangan pernah meng-uninstall aplikasinya (keduanya memutus sambungan).
    </div>

    <div class="card card-pad">
      <div class="wc-steps">

        <div class="wc-step">
          <div class="wc-num">1</div>
          <div class="min0">
            <h4>Siapkan app di Meta</h4>
            <p>
              Buat app tipe <b>Business</b> di developers.facebook.com, tambahkan produk <b>WhatsApp</b>,
              lalu buka <b>Facebook Login for Business</b> dan bikin satu konfigurasi Embedded Signup.
              Ambil tiga nilai ini, masukkan ke <span class="mono">.env</span>, lalu muat ulang halaman:
            </p>
            <div class="wc-keys">
              <div class="wc-key"><code>META_APP_ID</code> <span class="hint">App ID di dashboard app</span> @if($appId !== '')<span class="t-ok fs-12">terisi</span>@endif</div>
              <div class="wc-key"><code>META_APP_SECRET</code> <span class="hint">App Secret, jangan ditempel ke mana pun selain .env</span> @if($hasSecret)<span class="t-ok fs-12">terisi</span>@endif</div>
              <div class="wc-key"><code>META_ES_CONFIG_ID</code> <span class="hint">Configuration ID milik Embedded Signup</span> @if($configId !== '')<span class="t-ok fs-12">terisi</span>@endif</div>
              <div class="wc-key"><code>META_GRAPH_VERSION</code> <span class="hint">opsional, sekarang <b>{{ $graphVer }}</b> (samakan dengan versi di dashboard)</span></div>
            </div>
          </div>
        </div>

        <div class="wc-step">
          <div class="wc-num">2</div>
          <div class="min0">
            <h4>Sambungkan nomor bisnis</h4>
            <p>
              Tombol ini menjalankan Embedded Signup mode Coexistence. Pilih nomor bisnis yang sudah dipakai
              di aplikasi, lalu scan QR yang muncul memakai aplikasi WhatsApp Business di HP untuk memberi izin.
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <button type="button" class="btn btn-primary" id="wcStart" @disabled(! $envReady)>
                Hubungkan Nomor Bisnis
              </button>
              @if($envReady)
                <a href="{{ route('whatsapp.cloud.start') }}" class="btn btn-sm">Cara kedua (tanpa JS SDK)</a>
              @endif
            </div>
            @unless($envReady)
              <div style="color:var(--text-2);font-size:12px;margin-top:8px">Selesaikan langkah 1 dulu.</div>
            @endunless
            <div style="color:var(--text-2);font-size:12px;margin-top:8px">
              Tombol pertama memakai JavaScript SDK. Kalau ditolak dengan error domain
              (<span class="mono">1349048</span>), pakai cara kedua (jalur itu memakai redirect biasa dan
              cuma butuh <span class="mono">{{ route('whatsapp.cloud.callback') }}</span> terdaftar di
              <b>Valid OAuth Redirect URIs</b>).
            </div>

            @if(session('wc_error'))
              <div class="card card-pad" style="margin-top:12px;border-color:color-mix(in srgb,var(--err) 30%,var(--border));color:var(--err);font-size:13px">
                {{ session('wc_error') }}
              </div>
            @endif

            @if($res = session('wc_result'))
              <div class="wc-out" style="display:flex">
                <div style="font-size:13px">Berhasil. Salin ke <span class="mono">.env</span>:</div>
                <textarea readonly spellcheck="false">WA_CLOUD_PHONE_ID={{ $res['phone_id'] ?: 'ISI_MANUAL_DARI_API_SETUP' }}
WA_CLOUD_WABA_ID={{ $res['waba_id'] ?: 'ISI_MANUAL_DARI_API_SETUP' }}
WA_CLOUD_TOKEN={{ $res['token'] }}</textarea>
              </div>
            @endif

            <div class="wc-out" id="wcOut">
              <div style="font-size:13px">
                Hasil penyambungan. Baris yang berisi <span class="mono">BELUM_TERSAMBUNG</span> artinya
                wizard Coexistence tidak sampai selesai:
              </div>
              <div class="hint" id="wcNote"></div>
              <textarea id="wcEnv" readonly spellcheck="false"></textarea>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn btn-sm" id="wcCopy">Salin</button>
              </div>
              <div class="hint">
                Token ini tidak kedaluwarsa selama app dan izinnya tidak dicabut, jadi cukup dipasang sekali.
                Halaman ini sengaja tidak menulisnya sendiri ke .env. Kredensial produksi tidak boleh bisa
                diubah dari request web.
              </div>
            </div>
          </div>
        </div>

        <div class="wc-step">
          <div class="wc-num">3</div>
          <div class="min0">
            <h4>Aktifkan langganan webhook</h4>
            <p>
              Setelah kredensial terpasang, daftarkan app ke WhatsApp Business Account supaya status kirim
              dan balasan pelanggan ikut mengalir balik ke sistem.
            </p>
            <button type="button" class="btn btn-sm" id="wcSubscribe" @disabled(! $connected)>
              Daftarkan app ke WABA
            </button>
          </div>
        </div>

      </div>
    </div>

    <div class="card card-pad" style="color:var(--text-2);font-size:12.5px;line-height:1.7">
      <b class="t-ink">Yang berubah setelah ini aktif.</b>
      Pesan ke pelanggan di luar 24 jam sejak mereka menghubungi hanya boleh memakai template yang sudah
      disetujui Meta. Reminder dan tagihan aman karena polanya tetap; yang terbatas adalah broadcast dan
      kirim manual bebas dari panel. Biaya kategori utility sekitar Rp60 per pesan terkirim.
    </div>

  </div>

@endsection

@push('scripts')
<script>
(function () {
  var CFG = {
    appId:     @json($appId),
    configId:  @json($configId),
    graphVer:  @json($graphVer),
    csrf:      @json(csrf_token()),
    exchange:  @json(route('whatsapp.cloud.exchange')),
    verify:    @json(route('whatsapp.cloud.verify')),
    subscribe: @json(route('whatsapp.cloud.subscribe'))
  };

  var signup = { phoneId: null, wabaId: null, event: null };

  function showResult(token, note) {
    var lines = [
      'WA_CLOUD_PHONE_ID=' + (signup.phoneId || 'BELUM_TERSAMBUNG'),
      'WA_CLOUD_WABA_ID=' + (signup.wabaId || 'BELUM_TERSAMBUNG')
    ];
    if (token) lines.push('WA_CLOUD_TOKEN=' + token);
    document.getElementById('wcEnv').value = lines.join('\n');
    document.getElementById('wcNote').textContent =
      'Hasil flow: ' + (signup.event || 'tidak ada pesan dari popup') + (note ? ' · ' + note : '');
    document.getElementById('wcOut').style.display = 'flex';
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf, 'Accept': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Respons tidak terbaca' }; }); });
  }

  if (CFG.appId) {
    window.fbAsyncInit = function () {
      FB.init({ appId: CFG.appId, cookie: true, xfbml: false, version: CFG.graphVer });
    };
    var js = document.createElement('script');
    js.src = 'https://connect.facebook.net/en_US/sdk.js';
    js.async = true; js.defer = true; js.crossOrigin = 'anonymous';
    document.head.appendChild(js);
  }

  window.addEventListener('message', function (ev) {
    if (typeof ev.origin !== 'string') return;
    if (ev.origin.indexOf('facebook.com') === -1) return;
    var d;
    try { d = JSON.parse(ev.data); } catch (e) { return; }
    if (!d || d.type !== 'WA_EMBEDDED_SIGNUP') return;
    signup.event = d.event || null;
    if (d.data) {
      signup.phoneId = d.data.phone_number_id || signup.phoneId;
      signup.wabaId  = d.data.waba_id || signup.wabaId;
    }
  });

  var btn = document.getElementById('wcStart');
  if (btn) btn.addEventListener('click', function () {
    if (typeof FB === 'undefined') { window.vgToast('SDK Facebook belum termuat, coba muat ulang halaman.', 'err'); return; }
    btn.disabled = true;
    FB.login(function (resp) {
      btn.disabled = false;
      var code = resp && resp.authResponse && resp.authResponse.code;

      if (!code) { showResult(null, 'tidak ada code dari dialog'); return; }

      post(CFG.exchange, { code: code, waba_id: signup.wabaId, phone_id: signup.phoneId })
        .then(function (j) {
          if (j.ok) {
            signup.phoneId = j.phone_id || signup.phoneId;
            signup.wabaId  = j.waba_id || signup.wabaId;
            showResult(j.token, 'token ikut didapat');
          } else {
            showResult(null, 'penukaran token gagal (' + (j.error || '?') + '), tidak masalah, token dibuat manual');
          }
        });
    }, {
      config_id: CFG.configId,
      response_type: 'code',
      override_default_response_type: true,
      extras: {
        setup: {},
        featureType: 'whatsapp_business_app_onboarding',
        sessionInfoVersion: '2'
      }
    });
  });

  var copy = document.getElementById('wcCopy');
  if (copy) copy.addEventListener('click', function () {
    var ta = document.getElementById('wcEnv');
    ta.select();
    navigator.clipboard.writeText(ta.value).then(function () {
      copy.textContent = 'Tersalin';
      setTimeout(function () { copy.textContent = 'Salin'; }, 1800);
    });
  });

  var ver = document.getElementById('wcVerify');
  if (ver) ver.addEventListener('click', function () {
    ver.disabled = true; ver.textContent = 'Mengecek…';
    post(CFG.verify).then(function (j) {
      ver.disabled = false; ver.textContent = 'Tes kredensial';
      if (!j.ok) { window.vgToast('Gagal: ' + (j.error || 'tidak diketahui'), 'err'); return; }
      var n = j.number || {};
      window.vgToast('Kredensial hidup. Nomor ' + (n.display_phone_number || '-') +
            ' · nama terverifikasi ' + (n.verified_name || '-') +
            ' · kualitas ' + (n.quality_rating || '-'), 'ok');
    });
  });

  var sub = document.getElementById('wcSubscribe');
  if (sub) sub.addEventListener('click', function () {
    sub.disabled = true; sub.textContent = 'Mendaftarkan…';
    post(CFG.subscribe).then(function (j) {
      sub.disabled = false; sub.textContent = 'Daftarkan app ke WABA';
      window.vgToast(j.ok ? 'App terdaftar ke WABA.' : 'Gagal: ' + (j.error || 'tidak diketahui'), j.ok ? 'ok' : 'err');
    });
  });
})();
</script>
@endpush

@include('vouchers._toast')

@extends('layouts.app')

@section('title', 'Cek Voucher')
@section('page-title', 'Cek Voucher')


@section('page-class', 'pg-voucher-check-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Cek Voucher</h2>
      <p>Telusuri satu kode voucher ke semua router: status, bukti pemakaian, dan riwayat perangkatnya.</p>
    </div>
  </header>

  <div class="card mb-4">
    <div class="card-pad">
      <form class="row-wrap" id="vc-form">
        <div class="input-group" style="width:220px">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" name="kode" id="vc-kode" value="{{ $kode }}" class="input mono"
                 placeholder="Kode voucher, mis. 5Kqz27" autocomplete="off" autofocus>
        </div>

        <input type="text" name="pass" id="vc-pass" value="{{ $pass }}" class="input mono" style="width:170px"
               placeholder="Password kartu" autocomplete="off"
               title="Isi untuk mencocokkan password, atau isi ini saja kalau username di kartu tidak terbaca">

        <select name="router" id="vc-router" class="select" style="width:auto;min-width:150px">
          <option value="">Semua router</option>
          @foreach($routers as $rt)
            <option value="{{ $rt->slug }}" @selected($slug === $rt->slug)>{{ $rt->name }}</option>
          @endforeach
        </select>

        <button type="submit" class="btn btn-primary btn-sm" id="vc-submit">Periksa</button>
        <a href="{{ route('voucher-check.index') }}" class="btn btn-ghost btn-sm">Reset</a>

        <span class="hint">
          Huruf besar/kecil bebas. Pilih satu router kalau mau lebih cepat.
        </span>
      </form>
    </div>
  </div>

  <div id="vc-hasil"></div>

@endsection

@push('scripts')
<script>
(function () {
  const form   = document.getElementById('vc-form');
  const kode   = document.getElementById('vc-kode');
  const pass   = document.getElementById('vc-pass');
  const router = document.getElementById('vc-router');
  const tombol = document.getElementById('vc-submit');
  const kotak  = document.getElementById('vc-hasil');

  function tampilGagal(teks) {
    var d = document.createElement('div');
    d.className = 'card card-pad t-err';
    d.textContent = teks;
    kotak.replaceChildren(d);
  }
  const urlCari = @json(route('voucher-check.cari'));

  const SPINNER = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="12" cy="12" r="10" opacity=".2"/><path d="M22 12a10 10 0 0 0-10-10"/></svg>';

  const memuat = `
    <div class="card card-pad">
      <div class="vc-load">
        ${SPINNER}
        <div>
          <b>Memverifikasi voucher…</b>
          <span>Mencocokkan ke daftar user, catatan penjualan, dan sesi aktif di router. <i id="vc-detik"></i></span>
        </div>
      </div>
      <div class="vc-skel">
        <div class="skeleton" style="height:14px;width:45%"></div>
        <div class="row">
          <div class="skeleton spacer-12"></div><div class="skeleton spacer-12"></div>
          <div class="skeleton spacer-12"></div><div class="skeleton spacer-12"></div>
          <div class="skeleton spacer-12"></div><div class="skeleton spacer-12"></div>
        </div>
        <div class="skeleton" style="height:6px;margin-top:4px"></div>
      </div>
    </div>`;

  let jamTangan = null;

  function mulaiHitung() {
    const mulai = Date.now();
    const el = document.getElementById('vc-detik');
    jamTangan = setInterval(function () {
      const d = Math.round((Date.now() - mulai) / 1000);
      if (el) el.textContent = d + ' dtk';
    }, 1000);
  }

  function stopHitung() {
    if (jamTangan) { clearInterval(jamTangan); jamTangan = null; }
  }

  async function cari() {
    if (!kode.value.trim() && !pass.value.trim()) {
      kotak.innerHTML = '<div class="card card-pad muted">Isi kode voucher atau password kartunya dulu.</div>';
      return;
    }

    tombol.disabled = true;
    kotak.innerHTML = memuat;
    mulaiHitung();

    const q = new URLSearchParams();
    if (kode.value.trim())   q.set('kode', kode.value.trim());
    if (pass.value.trim())   q.set('pass', pass.value.trim());
    if (router.value)        q.set('router', router.value);
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));

    try {
      const res  = await fetch(urlCari + '?' + q.toString(), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!res.ok) {
        tampilGagal(data.pesan || 'Gagal memeriksa.');
      } else {
        kotak.innerHTML = data.html;
      }
    } catch (e) {
      tampilGagal('Gagal menghubungi server: ' + e.message);
    } finally {
      stopHitung();
      tombol.disabled = false;
    }
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); cari(); });

  if (kode.value.trim() || pass.value.trim()) cari();
})();
</script>
@endpush

@extends('layouts.app')

@section('title', 'Cek Voucher')
@section('page-title', 'Cek Voucher')


@section('page-class', 'pg-voucher-check-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Cek Voucher</h2>
      <p>Telusuri kartu voucher: batch, nomor, status di router, bukti pemakaian, dan riwayat hapusnya.</p>
    </div>
  </header>

  <div class="card mb-4">
    <div class="tabs card-tabs" role="tablist" aria-label="Mode cek">
      <button type="button" role="tab" data-mode="satu" class="{{ $mode === 'satu' ? 'active' : '' }}" aria-selected="{{ $mode === 'satu' ? 'true' : 'false' }}">Satu kartu</button>
      <button type="button" role="tab" data-mode="rentang" class="{{ $mode === 'rentang' ? 'active' : '' }}" aria-selected="{{ $mode === 'rentang' ? 'true' : 'false' }}">Rentang</button>
    </div>

    <div class="card-pad">
      <form id="vc-form-satu" data-panel="satu" autocomplete="off" @if($mode !== 'satu') hidden @endif>
        <div class="vc-isian">
          <div class="field">
            <label for="vc-no">No</label>
            <input type="number" id="vc-no" name="no" min="1" max="1000" class="input mono" inputmode="numeric" placeholder="414">
          </div>
          <div class="field">
            <label for="vc-username">Username</label>
            <input type="text" id="vc-username" name="username" value="{{ $kode }}" class="input mono" maxlength="64" placeholder="5K69994" autofocus>
          </div>
          <div class="field">
            <label for="vc-password">Password</label>
            <input type="text" id="vc-password" name="password" class="input mono" maxlength="64" placeholder="Password kartu">
          </div>
          <div class="field">
            <label for="vc-batch">Batch <span class="muted">(opsional)</span></label>
            <input type="text" id="vc-batch" name="batch" value="{{ $batch }}" class="input mono" maxlength="64" list="vc-batch-list" placeholder="Kode batch">
          </div>
          <div class="field">
            <label for="vc-router">Router</label>
            <select id="vc-router" name="router" class="select">
              <option value="">Semua router</option>
              @foreach($routers as $rt)
                <option value="{{ $rt->slug }}" @selected($slug === $rt->slug)>{{ $rt->name }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <input type="hidden" name="kartu_sumber">
        <input type="hidden" name="kartu_id">

        <div class="row-wrap vc-aksi">
          <button type="submit" class="btn btn-primary btn-sm">Periksa</button>
          <a href="{{ route('voucher-check.index') }}" class="btn btn-ghost btn-sm">Reset</a>
          <span class="hint">Isi Username, atau No + Batch. Password saja kalau username di kartu tidak terbaca.</span>
        </div>
      </form>

      <form id="vc-form-rentang" data-panel="rentang" autocomplete="off" @if($mode !== 'rentang') hidden @endif>
        @foreach(['dari' => 'Dari', 'sampai' => 'Sampai'] as $u => $judul)
          <fieldset class="vc-ujung">
            <legend>{{ $judul }}</legend>
            <div class="vc-isian">
              <div class="field">
                <label for="vc-{{ $u }}-no">No</label>
                <input type="number" id="vc-{{ $u }}-no" name="{{ $u }}_no" min="1" max="1000" class="input mono" inputmode="numeric" placeholder="{{ $u === 'dari' ? '414' : '450' }}">
              </div>
              <div class="field">
                <label for="vc-{{ $u }}-username">Username</label>
                <input type="text" id="vc-{{ $u }}-username" name="{{ $u }}_username" class="input mono" maxlength="64" placeholder="{{ $u === 'dari' ? '5K69994' : '5K58332' }}">
              </div>
              <div class="field">
                <label for="vc-{{ $u }}-password">Password</label>
                <input type="text" id="vc-{{ $u }}-password" name="{{ $u }}_password" class="input mono" maxlength="64" placeholder="Password kartu">
              </div>
            </div>
            <input type="hidden" name="{{ $u }}_kartu_sumber">
            <input type="hidden" name="{{ $u }}_kartu_id">
          </fieldset>
        @endforeach

        <div class="vc-isian">
          <div class="field">
            <label for="vc-r-batch">Batch <span class="muted">(opsional)</span></label>
            <input type="text" id="vc-r-batch" name="batch" value="{{ $batch }}" class="input mono" maxlength="64" list="vc-batch-list" placeholder="Kode batch">
          </div>
          <div class="field">
            <label for="vc-r-router">Router</label>
            <select id="vc-r-router" name="router" class="select">
              <option value="">Semua router</option>
              @foreach($routers as $rt)
                <option value="{{ $rt->slug }}" @selected($slug === $rt->slug)>{{ $rt->name }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="row-wrap vc-aksi">
          <button type="submit" class="btn btn-primary btn-sm">Periksa rentang</button>
          <a href="{{ route('voucher-check.index', ['mode' => 'rentang']) }}" class="btn btn-ghost btn-sm">Reset</a>
          <span class="hint">Tiap ujung: Username, atau No bila Batch diisi. Maksimal 1.000 kartu dalam satu batch.</span>
        </div>
      </form>
    </div>
  </div>

  <datalist id="vc-batch-list">
    @foreach($batches as $b)<option value="{{ $b }}">@endforeach
  </datalist>

  <div id="vc-hasil"></div>

@endsection

@push('scripts')
<script>
(function () {
  const csrf  = document.querySelector('meta[name="csrf-token"]').content;
  const kotak = document.getElementById('vc-hasil');
  const form  = { satu: document.getElementById('vc-form-satu'), rentang: document.getElementById('vc-form-rentang') };
  const url   = { satu: @json(route('voucher-check.cari')), rentang: @json(route('voucher-check.rentang')) };
  const tab   = Array.from(document.querySelectorAll('[role="tab"][data-mode]'));

  const SPINNER = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="12" cy="12" r="10" opacity=".2"/><path d="M22 12a10 10 0 0 0-10-10"/></svg>';

  const memuat = `
    <div class="card card-pad">
      <div class="vc-load">
        ${SPINNER}
        <div>
          <b>Memeriksa voucher…</b>
          <span>Mencocokkan ke panel, riwayat, daftar user, dan sesi aktif di router. <i id="vc-detik"></i></span>
        </div>
      </div>
      <div class="vc-skel">
        <div class="skeleton" style="height:14px;width:45%"></div>
        <div class="row">
          <div class="skeleton spacer-12"></div><div class="skeleton spacer-12"></div>
          <div class="skeleton spacer-12"></div><div class="skeleton spacer-12"></div>
        </div>
      </div>
    </div>`;

  let jamTangan = null;

  function mulaiHitung() {
    const mulai = Date.now();
    jamTangan = setInterval(function () {
      const el = document.getElementById('vc-detik');
      if (el) el.textContent = Math.round((Date.now() - mulai) / 1000) + ' dtk';
    }, 1000);
  }

  function stopHitung() {
    if (jamTangan) { clearInterval(jamTangan); jamTangan = null; }
  }

  function tampilGagal(teks) {
    const d = document.createElement('div');
    d.className = 'card card-pad t-err';
    d.textContent = teks;
    kotak.replaceChildren(d);
  }

  function isi(f, nama) {
    const el = f.elements[nama];
    return el ? el.value.trim() : '';
  }

  function kartu(f, awalan) {
    const s = f.elements[awalan + 'kartu_sumber'];
    const i = f.elements[awalan + 'kartu_id'];
    return s && i && s.value && i.value ? { sumber: s.value, id: i.value } : null;
  }

  function kosongkanKartu(f, awalan) {
    ['kartu_sumber', 'kartu_id'].forEach(function (n) {
      const el = f.elements[awalan + n];
      if (el) el.value = '';
    });
  }

  function pilihRouter(f, slug) {
    const sel = f.elements.router;
    if (sel && slug && Array.from(sel.options).some(function (o) { return o.value === slug; })) sel.value = slug;
  }

  const body = {
    satu: function () {
      const b = { no: isi(form.satu, 'no'), username: isi(form.satu, 'username'), password: isi(form.satu, 'password'), batch: isi(form.satu, 'batch'), router: isi(form.satu, 'router') };
      const k = kartu(form.satu, '');
      if (k) b.kartu = k;
      return b;
    },
    rentang: function () {
      const f = form.rentang;
      const ujung = function (a) {
        const u = { no: isi(f, a + '_no'), username: isi(f, a + '_username'), password: isi(f, a + '_password') };
        const k = kartu(f, a + '_');
        if (k) u.kartu = k;
        return u;
      };
      return { dari: ujung('dari'), sampai: ujung('sampai'), batch: isi(f, 'batch'), router: isi(f, 'router') };
    },
  };

  function pindahTab(mode) {
    tab.forEach(function (t) {
      const aktif = t.dataset.mode === mode;
      t.classList.toggle('active', aktif);
      t.setAttribute('aria-selected', aktif ? 'true' : 'false');
    });
    Object.keys(form).forEach(function (m) { form[m].hidden = m !== mode; });
    const q = new URLSearchParams(mode === 'rentang' ? { mode: 'rentang' } : {});
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
  }

  tab.forEach(function (t) {
    t.addEventListener('click', function () { pindahTab(t.dataset.mode); });
  });

  function simpanUrl(mode) {
    const f = form[mode];
    const q = new URLSearchParams();
    if (mode !== 'satu') q.set('mode', mode);
    if (mode === 'satu' && isi(f, 'username')) q.set('kode', isi(f, 'username'));
    if (isi(f, 'router')) q.set('router', isi(f, 'router'));
    if (isi(f, 'batch')) q.set('batch', isi(f, 'batch'));
    history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
  }

  async function kirim(mode) {
    const tombol = form[mode].querySelector('[type=submit]');
    tombol.disabled = true;
    kotak.innerHTML = memuat;
    mulaiHitung();
    simpanUrl(mode);

    try {
      const res  = await fetch(url[mode], {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body[mode]()),
      });
      const data = await res.json().catch(function () { return {}; });

      if (!res.ok) {
        tampilGagal(data.pesan || data.message || ('Gagal memeriksa (HTTP ' + res.status + ').'));
      } else {
        kotak.innerHTML = data.html || '';
      }
    } catch (e) {
      tampilGagal('Gagal menghubungi server: ' + e.message);
    } finally {
      stopHitung();
      tombol.disabled = false;
    }
  }

  Object.keys(form).forEach(function (mode) {
    form[mode].addEventListener('submit', function (e) { e.preventDefault(); kirim(mode); });
  });

  ['username', 'no', 'batch'].forEach(function (n) {
    form.satu.elements[n].addEventListener('input', function () { kosongkanKartu(form.satu, ''); });
  });

  ['dari', 'sampai'].forEach(function (a) {
    ['_username', '_no'].forEach(function (n) {
      form.rentang.elements[a + n].addEventListener('input', function () { kosongkanKartu(form.rentang, a + '_'); });
    });
  });
  form.rentang.elements.batch.addEventListener('input', function () {
    kosongkanKartu(form.rentang, 'dari_');
    kosongkanKartu(form.rentang, 'sampai_');
  });

  kotak.addEventListener('click', function (e) {
    const b = e.target.closest('[data-pilih-id]');
    if (!b) return;

    const mode   = b.dataset.ujung ? 'rentang' : 'satu';
    const f      = form[mode];
    const awalan = b.dataset.ujung ? b.dataset.ujung + '_' : '';
    if (!f) return;

    f.elements[awalan + 'kartu_sumber'].value = b.dataset.pilihSumber;
    f.elements[awalan + 'kartu_id'].value     = b.dataset.pilihId;
    if (b.dataset.pilihBatch) f.elements.batch.value = b.dataset.pilihBatch;
    pilihRouter(f, b.dataset.pilihRouter);
    kirim(mode);
  });

  if (isi(form.satu, 'username')) kirim('satu');
})();
</script>
@endpush

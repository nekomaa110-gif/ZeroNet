@extends('layouts.app')

@section('title', 'Generator Voucher')
@section('page-title', 'Generator Voucher')

@section('page-class', 'pg-voucher-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Generator Voucher</h2>
      <p>Buat, cetak, dan pantau voucher hotspot lokal, langsung ke router.</p>
    </div>
    @if (auth()->user()?->isAdmin())
      <div class="head-actions">
        <a href="{{ route('voucher-scripts.index') }}" class="btn btn-ghost">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          Script Router
        </a>
        <button type="button" class="btn" @click="$dispatch('voucher-cepat')" x-data>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Tambah 1
        </button>
        <button type="button" class="btn btn-primary" id="vgOpen">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Buat Voucher
        </button>
      </div>
    @endif
  </header>

  @include('vouchers._ringkasan')

  <div class="card">
    <form method="GET" action="{{ route('vouchers.index') }}" class="toolbar" data-live-target="#voucher-batches">
      <div class="input-group tb-search">
        <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" value="{{ $filters['q'] }}" class="input" data-live-search
               placeholder="Cari batch, catatan, profil…" aria-label="Cari batch">
      </div>

      <select name="router" class="select tb-select" data-live-submit aria-label="Filter router">
        <option value="">Semua router</option>
        @foreach ($routers as $r)
          <option value="{{ $r->slug }}" @selected($filters['router'] === $r->slug)>{{ $r->name }}</option>
        @endforeach
      </select>

      <select name="status" class="select tb-select" data-live-submit aria-label="Filter status">
        <option value="">Semua status</option>
        @foreach (['success' => 'Berhasil', 'syncing' => 'Sinkronisasi', 'pending' => 'Menunggu', 'partial' => 'Sebagian gagal', 'failed' => 'Gagal'] as $k => $v)
          <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $v }}</option>
        @endforeach
      </select>

      <div class="tb-end">
        <span class="live">Live</span>
        @if (auth()->user()?->isAdmin())
          <button type="button" class="btn btn-sm" id="vgSyncBtn" title="Tarik keadaan terbaru dari router yang dipilih di filter">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Sinkron
          </button>
          <button type="button" class="btn btn-sm" @click="$dispatch('impor-mikhmon')" x-data>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Impor dari router
          </button>
        @endif
      </div>
    </form>

  <div id="voucher-batches" data-live-results>
      @include('vouchers._batches')
    </div>
  </div>

  @if (auth()->user()?->isAdmin())
    @include('vouchers._generator-modal')
    @include('vouchers._import-modal')
    @include('vouchers._cepat-modal')
  @endif
  @include('vouchers._toast')

@endsection

@push('scripts')
<script>
(function () {
  const btn = document.getElementById('vgSyncBtn');
  if (!btn) return;

  const url  = @json(route('vouchers.reconcile'));
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  btn.addEventListener('click', async function () {
    const pick = document.querySelector('#voucher-batches')?.closest('.card')?.querySelector('select[name="router"]');
    const slug = pick && pick.value;

    if (!slug) {
      window.vgToast('Pilih satu router dulu di filter, baru tekan Sinkron.', 'warn');
      return;
    }

    const teks = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Menghubungi router…';

    try {
      const res  = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ router: slug }),
      });
      const data = await res.json();

      if (data.success) {
        window.vgToast(data.message, 'ok');
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        window.vgToast(data.error || 'Gagal menyinkronkan.', 'err');
      }
    } catch (e) {
      window.vgToast('Gagal menghubungi server: ' + e.message, 'err');
    } finally {
      btn.disabled = false;
      btn.innerHTML = teks;
    }
  });
})();

@if (auth()->user()?->isAdmin())
(function () {
  const daftar = document.getElementById('voucher-batches');
  if (!daftar) return;

  const csrf   = document.querySelector('meta[name="csrf-token"]').content;
  const angka  = n => Number(n).toLocaleString('id-ID');
  const kotak  = () => Array.from(daftar.querySelectorAll('[data-pilih]:not(:disabled)'));
  const jumlah = (pilih, kunci) => pilih.reduce((n, c) => n + Number(c.dataset[kunci] || 0), 0);
  const sebut  = nama => nama.length > 1 ? nama.slice(0, -1).join(', ') + ' dan ' + nama[nama.length - 1] : nama[0];
  let berjalan = false;

  function segarkan() {
    const bar = daftar.querySelector('[data-massal]');
    if (!bar || berjalan) return;
    const semua = kotak();
    const pilih = semua.filter(c => c.checked);
    daftar.querySelectorAll('[data-pilih-semua]').forEach(kepala => {
      kepala.checked = pilih.length > 0 && pilih.length === semua.length;
      kepala.indeterminate = pilih.length > 0 && pilih.length < semua.length;
    });
    bar.hidden = pilih.length === 0;
    bar.querySelector('[data-massal-teks]').textContent = angka(pilih.length) + ' batch dipilih · ' + angka(jumlah(pilih, 'kartu')) + ' kartu';
  }

  daftar.addEventListener('change', function (e) {
    if (e.target.matches('[data-pilih-semua]')) kotak().forEach(c => { c.checked = e.target.checked; });
    if (e.target.matches('[data-pilih-semua], [data-pilih]')) segarkan();
  });

  window.addEventListener('beforeunload', function (e) {
    if (!berjalan) return;
    e.preventDefault();
    e.returnValue = true;
  });

  daftar.addEventListener('click', async function (e) {
    if (e.target.closest('[data-massal-batal]')) {
      kotak().forEach(c => { c.checked = false; });
      segarkan();
      return;
    }
    const tombol = e.target.closest('[data-massal-hapus]');
    const pilih  = kotak().filter(c => c.checked);
    if (!tombol || berjalan || !pilih.length) return;

    const keRouter = tombol.dataset.massalHapus === 'router';
    const n     = pilih.length;
    const kartu = angka(jumlah(pilih, 'kartu'));
    const siap  = jumlah(pilih, 'siap');
    const aktif = jumlah(pilih, 'aktif');

    let pesan = keRouter
      ? 'Hapus ' + angka(n) + ' batch DAN ' + kartu + ' kartunya dari router ' + sebut([...new Set(pilih.map(c => c.dataset.router))]) + '? Kartu yang sudah terjual akan langsung mati.'
      : 'Hapus ' + angka(n) + ' batch beserta ' + kartu + ' kartunya dari panel? Kartu di router TIDAK ikut dihapus.';
    if (keRouter && siap) pesan += '\n' + angka(siap) + ' kartu Belum dipakai ikut dicabut dan tidak bisa dijual lagi.';
    if (keRouter && aktif) pesan += '\n' + angka(aktif) + ' kartu Aktif sedang dipakai pelanggan dan akan langsung terputus.';
    pesan += '\n\n' + pilih.slice(0, 5).map(c => c.dataset.kode).join('\n') + (n > 5 ? '\n+' + angka(n - 5) + ' batch lainnya' : '');

    const jawab = await window.zeroConfirm({
      title: keRouter ? 'Hapus dari Router' : 'Hapus Batch',
      message: pesan,
      action: 'Hapus ' + angka(n) + ' batch',
      variant: 'danger',
      requirePassword: keRouter,
      intent: 'Menghapus ' + n + ' batch' + (keRouter ? ' beserta kartunya dari router' : ' dari panel'),
    });
    if (keRouter ? !(jawab && jawab.ok) : !jawab) return;

    berjalan = true;
    const teks    = daftar.querySelector('[data-massal-teks]');
    const dikunci = Array.from(daftar.querySelectorAll('[data-pilih]:not(:disabled), [data-pilih-semua], [data-massal] button'));
    dikunci.forEach(el => { el.disabled = true; });

    const isi         = JSON.stringify(keRouter ? { remove_from_router: true, current_password: jawab.password } : {});
    const gagal       = new Map();
    const routerGagal = new Map();
    let dihapus = 0, dicabut = 0, henti = '';

    for (let i = 0; i < n; i++) {
      const c = pilih[i];
      teks.textContent = 'Menghapus ' + angka(i + 1) + ' dari ' + angka(n) + ' batch…';
      let alasan = henti || routerGagal.get(c.dataset.router) || '';
      if (!alasan) {
        try {
          const res = await fetch(c.dataset.hapus, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: isi,
          });
          const data = await res.json().catch(() => ({}));
          if (res.ok && data.success) {
            dihapus++;
            dicabut += Number(data.dicabut || 0);
            continue;
          }
          alasan = data.error || data.message || ('HTTP ' + res.status);
          if (data.sebab === 'password' || [401, 403, 419].includes(res.status)) henti = alasan;
          if (data.sebab === 'router') routerGagal.set(c.dataset.router, alasan);
        } catch (err) {
          alasan = henti = 'Tidak bisa menghubungi server: ' + err.message;
        }
      }
      gagal.set(alasan, (gagal.get(alasan) || []).concat(c.dataset.kode));
    }

    const ringkas = [];
    if (dihapus) ringkas.push(angka(dihapus) + ' batch dihapus dari panel' + (keRouter ? ', ' + angka(dicabut) + ' kartu dicabut dari router.' : '.'));
    gagal.forEach((kode, alasan) => ringkas.push(angka(kode.length) + ' batch gagal (' + kode.join(', ') + '): ' + alasan));
    window.vgToast(ringkas.join(' '), gagal.size ? 'err' : 'ok');

    berjalan = false;
    if (dihapus) {
      daftar.dispatchEvent(new CustomEvent('live-search:muat-ulang', { bubbles: true }));
      return;
    }
    dikunci.forEach(el => { el.disabled = false; });
    segarkan();
  });
})();
@endif
</script>
@endpush

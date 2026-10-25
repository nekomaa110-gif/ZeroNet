@extends('layouts.app')

@section('title', 'Batch ' . $batch->code)
@section('page-title', 'Batch Voucher')
@section('page-class', 'pg-voucher-batch')

@section('content')

  @php
    $angka = fn ($n) => number_format((int) $n, 0, ',', '.');
    $catatan = $batch->label && ! str_contains($batch->code, $batch->label) ? $batch->label : null;
    $pembuat = $batch->creator?->name ?? $batch->creator?->username;
  @endphp

  <header class="page-head">
    <div class="min0">
      <h2 class="vb-judul mono">{{ $batch->code }}</h2>
      <p>
        {{ $angka($batch->quantity) }} kartu · {{ $batch->profile }} · {{ $batch->router_label }}
        @if ($catatan) · {{ $catatan }} @endif
      </p>
    </div>
    <div class="head-actions">
      <a href="{{ route('vouchers.index') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Kembali
      </a>
      <a href="{{ route('vouchers.export', $batch) }}" class="btn" data-page-transition="none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        CSV
      </a>
      <button type="button" class="btn btn-primary" id="vbPrintOpen">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Cetak Kartu
      </button>
    </div>
  </header>

  <section class="card vb-ringkas" aria-label="Status dan info batch">
    <div class="vb-status">
      <div class="vb-status-atas">
        <span class="badge {{ $batch->status_tone }} solid">{{ $batch->status_label }}</span>
        <span class="vb-hitung" id="vbCount">
          {{ $angka($batch->synced_count) }}/{{ $angka($batch->quantity) }} kartu ada di router
          @if ($batch->failed_count > 0) · {{ $angka($batch->failed_count) }} gagal @endif
        </span>
        @if (auth()->user()?->isAdmin())
          <button type="button" class="btn btn-sm push-end" id="vbResync">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.13-9.36L23 10"/></svg>
            Sinkron ulang
          </button>
        @endif
      </div>

      <div class="progress {{ $batch->status === 'failed' ? 'err' : ($batch->status === 'partial' ? 'warn' : ($batch->status === 'success' ? 'ok' : '')) }}" id="vbBarWrap">
        <i id="vbBar" style="width:{{ $batch->progress }}%"></i>
      </div>

      @if ($batch->error)
        <div class="vb-galat">{{ $batch->error }}</div>
      @endif

      <div class="vb-pakai">
        <span><b class="t-ink tnum">{{ $angka($ringkas['ready'] ?? 0) }}</b> belum dipakai</span>
        <span><b class="t-ok tnum">{{ $angka($ringkas['active'] ?? 0) }}</b> aktif</span>
        <span><b class="t-warn tnum">{{ $angka($ringkas['expired'] ?? 0) }}</b> habis</span>
        @if (($ringkas['missing'] ?? 0) > 0)
          <span><b class="t-err tnum">{{ $angka($ringkas['missing']) }}</b> hilang dari router</span>
        @endif
      </div>
    </div>

    <dl class="vb-fakta">
      <div><dt>Router</dt><dd>{{ $batch->router_label }}</dd></div>
      <div><dt>Profil</dt><dd>{{ $batch->profile }}</dd></div>
      <div><dt>Server hotspot</dt><dd>{{ $batch->server ?: 'all' }}</dd></div>
      <div><dt>Masa aktif</dt><dd>{{ $batch->validity ?: 'tidak diatur' }} @if ($batch->validity)<span class="t-mute">sejak login pertama</span>@endif</dd></div>
      <div><dt>Jatah jam</dt><dd>{{ $batch->limit_uptime ?: 'tanpa batas' }}</dd></div>
      <div><dt>Kuota data</dt><dd>{{ $batch->limit_bytes ? \App\Services\MikrotikService::bytes($batch->limit_bytes) : 'tanpa kuota' }}</dd></div>
      <div><dt>Harga</dt><dd>{{ $batch->price ? 'Rp ' . $angka($batch->price) : 'belum diisi' }}</dd></div>
      <div><dt>Bentuk kartu</dt><dd>{{ $batch->mode === 'up' ? 'Username + password' : 'Satu kode' }} · {{ $batch->code_length }} karakter</dd></div>
      <div>
        <dt>Dibuat</dt>
        <dd>
          {{ $batch->created_at?->format('d M Y H:i') }}
          @if ($pembuat)<span class="t-mute">oleh {{ $pembuat }}</span>@elseif ($batch->source === 'mikhmon_import')<span class="t-mute">diimpor dari router</span>@endif
        </dd>
      </div>
    </dl>
  </section>

  <div class="card">
    <form method="GET" action="{{ route('vouchers.show', $batch) }}" class="toolbar" data-live-target="#voucher-list">
      <div class="input-group tb-search">
        <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" value="{{ $filters['q'] }}" class="input mono" data-live-search placeholder="Cari kode kartu…" aria-label="Cari kode kartu">
      </div>

      <select name="status" class="select tb-select" data-live-submit aria-label="Filter status kartu">
        <option value="">Semua status</option>
        @foreach (['ready' => 'Belum dipakai', 'active' => 'Aktif', 'expired' => 'Habis', 'disabled' => 'Dinonaktifkan', 'missing' => 'Hilang dari router'] as $k => $v)
          <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $v }}</option>
        @endforeach
      </select>

      <select name="sync" class="select tb-select" data-live-submit aria-label="Filter sinkronisasi">
        <option value="">Semua sinkronisasi</option>
        <option value="success" @selected($filters['sync'] === 'success')>Sudah di router</option>
        <option value="failed" @selected($filters['sync'] === 'failed')>Gagal masuk</option>
        <option value="pending" @selected($filters['sync'] === 'pending')>Belum dikirim</option>
      </select>

      <div class="tb-end">
        @if (auth()->user()?->isAdmin() && $batch->router_id)
          <button type="button" class="btn btn-sm vb-btn-bahaya" data-hapus-habis-semua>Hapus semua Habis</button>
        @endif
        <span class="live">Live</span>
      </div>
    </form>

    <div id="voucher-list" data-live-results>
      @include('vouchers._list')
    </div>
  </div>

  @if (auth()->user()?->isAdmin())
    <section class="card vb-bahaya" aria-labelledby="vbBahayaJudul">
      <h3 id="vbBahayaJudul">Hapus batch</h3>
      <div class="vb-bahaya-baris">
        <div>
          <b>Hapus dari panel saja</b>
          <p>Batch dan {{ $angka($batch->quantity) }} kartunya hilang dari panel. Kartu di router tetap bisa dipakai.</p>
        </div>
        <form class="inline-form" method="POST" action="{{ route('vouchers.destroy', $batch) }}"
              data-confirm="Hapus batch {{ $batch->code }} beserta {{ $angka($batch->quantity) }} kartunya dari panel? Kartu di router TIDAK ikut dihapus."
              data-confirm-title="Hapus Batch"
              data-confirm-action="Hapus"
              data-confirm-variant="danger">
          @csrf @method('DELETE')
          <button type="submit" class="btn btn-sm vb-btn-bahaya">Hapus dari panel</button>
        </form>
      </div>
      <div class="vb-bahaya-baris">
        <div>
          <b>Hapus dan cabut dari router</b>
          <p>Semua kartu dihapus juga dari router {{ $batch->router_label }}. Kartu yang sudah terjual langsung mati. Perlu password Anda.</p>
        </div>
        <button type="button" class="btn btn-sm vb-btn-bahaya" data-hapus-batch-router>Hapus + cabut dari router</button>
      </div>
    </section>
  @endif

  <div class="mdl-backdrop" id="vbPrint" role="dialog" aria-modal="true" hidden>
    <div class="mdl mdl-sm">
      <div class="mdl-head">
        <div class="mdl-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </div>
        <div class="grow">
          <h3 class="mdl-title">Cetak Kartu</h3>
          <p class="mdl-sub">Halaman cetak terbuka di tab baru (tekan Ctrl+P, pilih Simpan sebagai PDF).</p>
        </div>
        <button type="button" class="mdl-close" id="vbPrintClose" aria-label="Tutup">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="mdl-body">
        <div class="field">
          <label>Susunan kartu</label>
          <select class="select" id="vbTpl">
            @foreach (\App\Models\PrintTemplate::pilihan() as $key => $label)
              <option value="{{ $key }}" @selected($key === ($batch->router?->template_cetak ?: 'v4'))>{{ $label }}</option>
            @endforeach
          </select>
        </div>

        <div class="field" style="margin-top:12px">
          <label>Kartu yang dicetak</label>
          <select class="select" id="vbFilter">
            <option value="">Semua kartu di batch ini</option>
            <option value="belum=1">Hanya yang belum pernah dicetak</option>
            <option value="status=ready">Hanya yang belum dipakai</option>
          </select>
        </div>
      </div>

      <div class="mdl-foot">
        <span class="grow"></span>
        <button type="button" class="btn btn-sm" id="vbPrintCancel">Batal</button>
        <a href="#" target="_blank" class="btn btn-sm btn-primary" id="vbPrintGo" data-page-transition="none">Buka halaman cetak</a>
      </div>
    </div>
  </div>

  @include('vouchers._toast')

@endsection

@push('scripts')
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  const resync = document.getElementById('vbResync');
  const urlResync   = @json(route('vouchers.resync', $batch));
  const urlProgress = @json(route('vouchers.progress', $batch));
  let polling = null;

  function pantau() {
    if (polling) return;

    polling = setInterval(async function () {
      try {
        const res  = await fetch(urlProgress, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        document.getElementById('vbBar').style.width = data.progress + '%';
        document.getElementById('vbCount').textContent =
          data.synced.toLocaleString('id-ID') + '/' + data.quantity.toLocaleString('id-ID') + ' kartu ada di router'
          + (data.failed ? ' · ' + data.failed + ' gagal' : '');

        if (data.done) {
          clearInterval(polling); polling = null;
          window.vgToast(
            data.status === 'success' ? 'Semua kartu sudah ada di router.' : (data.error || data.failed + ' kartu masih gagal.'),
            data.status === 'success' ? 'ok' : 'warn'
          );
          setTimeout(function () { location.reload(); }, 1500);
        }
      } catch (e) {}
    }, 1500);
  }

  resync && resync.addEventListener('click', async function () {
    resync.disabled = true;
    const teks = resync.innerHTML;
    resync.textContent = 'Mengirim…';

    try {
      const res  = await fetch(urlResync, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      });
      const data = await res.json();

      window.vgToast(data.message || data.error, data.success ? 'ok' : 'err');
      if (data.success) pantau();
    } catch (e) {
      window.vgToast('Gagal menghubungi server: ' + e.message, 'err');
    } finally {
      resync.disabled = false;
      resync.innerHTML = teks;
    }
  });

  @if (in_array($batch->status, ['pending', 'syncing']))
    pantau();
  @endif

  document.addEventListener('click', async function (e) {
    const btn = e.target.closest('[data-hapus-kartu]');
    if (!btn) return;

    const ok = await window.zeroConfirm({
      title: 'Hapus Kartu',
      message: 'Hapus kartu ' + btn.dataset.kode + ' dari panel dan dari router?',
      action: 'Hapus',
      variant: 'danger',
    });

    if (!ok) return;

    btn.disabled = true;

    try {
      const res = await fetch(btn.dataset.hapusKartu + '?remove_from_router=1', {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      });
      const data = await res.json();

      window.vgToast(data.message || data.error, data.success ? 'ok' : 'err');
      if (data.success) btn.closest('tr').remove();
    } catch (err) {
      window.vgToast('Gagal menghapus: ' + err.message, 'err');
    } finally {
      btn.disabled = false;
    }
  });

  @if (auth()->user()?->isAdmin())
  const hapusMassal    = @json(route('vouchers.destroy-many', $batch));
  const pratinjauKartu = @json(route('vouchers.preview-destroy-many', $batch), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
  const pratinjauBatch = @json(route('vouchers.preview-destroy', $batch), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
  const hapusBatch     = @json(route('vouchers.destroy', $batch));
  const daftarBatch    = @json(route('vouchers.index'));
  const kodeBatch      = @json($batch->code);
  const perkiraan      = @json(max(1, (int) $batch->quantity));
  const labelStatus = { ready: 'Belum dipakai', active: 'Aktif', expired: 'Habis', disabled: 'Dinonaktifkan', missing: 'Hilang dari router' };
  const angka = n => Number(n).toLocaleString('id-ID');
  let semuaFilter = false;

  const daftar = () => document.getElementById('voucher-list');
  const kotak  = () => Array.from(daftar().querySelectorAll('[data-pilih]'));
  const info   = () => daftar().querySelector('[data-daftar-info]');

  async function kirimJson(url, metode, isi) {
    const res = await fetch(url, {
      method: metode,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
      body: JSON.stringify(isi),
    });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  }

  function barisDetail(data) {
    const baris = (data.ditahan || []).map(d => 'No ' + (d.nomor ?? '-') + ' ' + d.username + ': ' + d.alasan);
    if (Number(data.ditahan_lain) > 0) baris.push('dan ' + data.ditahan_lain + ' kartu lain');
    return baris;
  }

  function sebabGagal(hasil, awal) {
    return hasil.data.error || hasil.data.message || (awal + ' (HTTP ' + hasil.res.status + ').');
  }

  async function hapusKartu(tombol, habis, isi) {
    const badan = habis ? Object.assign({}, isi, { mode: 'habis' }) : isi;
    tombol.disabled = true;
    let selesai = false;
    try {
      const pra = await kirimJson(pratinjauKartu, 'POST', badan);
      if (!pra.res.ok || !pra.data.success) {
        window.vgToast(sebabGagal(pra, 'Gagal memeriksa kartu'), 'err');
        return;
      }

      const data     = pra.data;
      const kandidat = Number(data.kandidat);
      const detail   = barisDetail(data);
      const ditahan  = (data.ditahan || []).length + Number(data.ditahan_lain || 0);

      if (kandidat - ditahan < 1) {
        await window.zeroConfirm({
          variant: 'warning',
          title: habis ? 'Hapus kartu Habis' : 'Hapus kartu',
          message: data.pesan,
          detail: detail,
          action: 'Mengerti',
          cancel: 'Tutup',
        });
        return;
      }

      const akanDihapus = kandidat - ditahan;
      const jawab = await window.zeroConfirm({
        variant: 'danger',
        title: habis ? 'Hapus kartu Habis' : 'Hapus ' + angka(akanDihapus) + ' kartu',
        message: data.pesan,
        detail: detail,
        action: 'Hapus ' + angka(akanDihapus) + ' kartu',
        requirePassword: true,
        intent: 'Menghapus ' + akanDihapus + (habis ? ' kartu Habis' : ' kartu') + ' dari batch ' + kodeBatch,
      });
      if (!jawab || !jawab.ok) return;

      const hapus = await kirimJson(hapusMassal, 'DELETE', Object.assign({ password: jawab.password }, badan, { jumlah: kandidat }));
      if (hapus.res.ok && hapus.data.success) {
        const sebagian = (Array.isArray(hapus.data.ditahan) ? hapus.data.ditahan.length : Number(hapus.data.ditahan || 0)) + Number(hapus.data.ditahan_lain || 0);
        window.vgToast(hapus.data.message, sebagian ? 'warn' : 'ok');
        selesai = true;
        setTimeout(() => location.reload(), sebagian ? 1800 : 900);
        return;
      }
      window.vgToast(sebabGagal(hapus, 'Gagal menghapus'), 'err');
    } catch (err) {
      window.vgToast('Gagal menghapus: ' + err.message, 'err');
    } finally {
      if (!selesai) tombol.disabled = false;
    }
  }

  async function hapusBatchRouter(tombol) {
    tombol.disabled = true;
    let selesai = false;
    try {
      const pra = await kirimJson(pratinjauBatch, 'POST', {});
      if (!pra.res.ok || !pra.data.success) {
        window.vgToast(sebabGagal(pra, 'Gagal memeriksa batch'), 'err');
        return;
      }

      const jawab = await window.zeroConfirm({
        variant: 'danger',
        title: 'Hapus dari Router',
        message: 'Batch ' + kodeBatch + ': ' + pra.data.pesan,
        detail: barisDetail(pra.data),
        action: 'Hapus semuanya',
        requirePassword: true,
        intent: 'Menghapus batch ' + kodeBatch + ' beserta kartunya dari router',
      });
      if (!jawab || !jawab.ok) return;

      const hapus = await kirimJson(hapusBatch, 'DELETE', { remove_from_router: true, current_password: jawab.password });
      if (hapus.res.ok && hapus.data.success) {
        selesai = true;
        if (Number(hapus.data.ditahan || 0) === 0) {
          window.location.href = daftarBatch;
          return;
        }
        window.vgToast(hapus.data.message, 'warn');
        setTimeout(() => location.reload(), 1800);
        return;
      }
      window.vgToast(sebabGagal(hapus, 'Gagal menghapus'), 'err');
    } catch (err) {
      window.vgToast('Gagal menghapus: ' + err.message, 'err');
    } finally {
      if (!selesai) tombol.disabled = false;
    }
  }

  function segarkanMassal() {
    const bar = daftar().querySelector('[data-massal]');
    if (!bar) return;
    const semua   = kotak();
    const dipilih = semua.filter(c => c.checked);
    const total   = Number(info().dataset.total);
    daftar().querySelectorAll('[data-pilih-semua]').forEach(kepala => {
      kepala.checked = dipilih.length > 0 && dipilih.length === semua.length;
      kepala.indeterminate = dipilih.length > 0 && dipilih.length < semua.length;
    });
    if (dipilih.length < semua.length) semuaFilter = false;
    const jumlah = semuaFilter ? total : dipilih.length;
    bar.hidden = jumlah === 0;
    bar.querySelector('[data-massal-teks]').textContent = semuaFilter
      ? 'Semua ' + angka(total) + ' kartu yang cocok dengan filter dipilih'
      : angka(jumlah) + ' kartu dipilih';
    const tombolSemua = bar.querySelector('[data-massal-semua]');
    tombolSemua.hidden = semuaFilter || dipilih.length !== semua.length || total <= semua.length;
    tombolSemua.textContent = 'Pilih semua ' + angka(total) + ' kartu yang cocok dengan filter';
    bar.querySelector('[data-massal-hapus]').textContent = 'Hapus ' + angka(jumlah) + ' kartu';
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-pilih-semua]')) {
      kotak().forEach(c => { c.checked = e.target.checked; });
      semuaFilter = false;
      segarkanMassal();
    } else if (e.target.matches('[data-pilih]')) {
      segarkanMassal();
    }
  });

  document.addEventListener('live-search:loaded', function () { semuaFilter = false; });

  document.addEventListener('click', async function (e) {
    if (e.target.closest('[data-massal-semua]')) { semuaFilter = true; segarkanMassal(); return; }
    if (e.target.closest('[data-massal-batal]')) {
      kotak().forEach(c => { c.checked = false; });
      semuaFilter = false;
      segarkanMassal();
      return;
    }

    const batchRouter = e.target.closest('[data-hapus-batch-router]');
    if (batchRouter) { await hapusBatchRouter(batchRouter); return; }

    @if ($batch->router_id)
    const habisSemua = e.target.closest('[data-hapus-habis-semua]');
    if (habisSemua) {
      const form  = habisSemua.closest('form');
      const bantu = info() ? Number(info().dataset.total) : 0;
      await hapusKartu(habisSemua, true, {
        semua: true,
        jumlah: bantu || perkiraan,
        sync: form.elements.sync.value,
        q: form.elements.q.value,
      });
      return;
    }
    const habisBar = e.target.closest('[data-massal-habis]');
    @else
    const habisBar = null;
    @endif
    const tombol = habisBar || e.target.closest('[data-massal-hapus]');
    if (!tombol) return;

    const d   = info().dataset;
    const ids = kotak().filter(c => c.checked).map(c => Number(c.value));
    if (!(semuaFilter ? Number(d.total) : ids.length)) return;

    const habis = !!habisBar;
    let isi;
    if (!semuaFilter) isi = { ids: ids };
    else if (habis) isi = { semua: true, jumlah: Number(d.total), sync: d.sync, q: d.q };
    else isi = { semua: true, jumlah: Number(d.total), status: d.status, sync: d.sync, q: d.q };

    await hapusKartu(tombol, habis, isi);
  });
  @endif

  const modal = document.getElementById('vbPrint');
  const base  = @json(route('vouchers.print', $batch));

  function perbarui() {
    const t = document.getElementById('vbTpl').value;
    const f = document.getElementById('vbFilter').value;
    document.getElementById('vbPrintGo').href = base + '?t=' + t + (f ? '&' + f : '');
  }

  function buka()  { modal.hidden = false; requestAnimationFrame(() => modal.classList.add('is-open')); perbarui(); }
  function tutup() { modal.classList.remove('is-open'); setTimeout(() => { modal.hidden = true; }, 180); }

  document.getElementById('vbPrintOpen').addEventListener('click', buka);
  document.getElementById('vbPrintClose').addEventListener('click', tutup);
  document.getElementById('vbPrintCancel').addEventListener('click', tutup);
  modal.addEventListener('click', e => { if (e.target === modal) tutup(); });
  ['vbTpl', 'vbFilter'].forEach(id => document.getElementById(id).addEventListener('change', perbarui));
  document.getElementById('vbPrintGo').addEventListener('click', function () { setTimeout(tutup, 300); });
})();
</script>
@endpush

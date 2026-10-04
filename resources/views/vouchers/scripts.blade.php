@extends('layouts.app')

@section('title', 'Script Router')
@section('page-title', 'Script Router')

@section('content')

  <header class="page-head">
    <div>
      <h2>Script Router</h2>
      <p>Script on-login dan pengawas kedaluwarsa di tiap router, dipakai bersama semua profil. Profil hotspot dikelola di halaman Paket.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('vouchers.index') }}" class="btn btn-ghost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Kembali
      </a>
      <a href="{{ route('packages.index') }}" class="btn">Kelola profil di Paket</a>
    </div>
  </header>

  <div class="card card-pad mb-4">
    <div style="display:flex;gap:12px;align-items:flex-start">
      <div style="width:32px;height:32px;border-radius:9px;flex:none;display:grid;place-items:center;background:var(--bg-mute);color:var(--text-2)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      </div>
      <div style="font-size:12.5px;color:var(--text-2);line-height:1.6">
        <b class="t-ink">Apa yang dipasang.</b>
        Satu <span class="mono">{{ config('voucher.script_name') }}</span> berisi logika on-login (mencap masa aktif
        kartu saat login pertama dan menulis catatan penjualan), dan satu
        <span class="mono">{{ config('voucher.scheduler_name') }}</span> yang tiap
        {{ config('voucher.scheduler_interval') }} memeriksa masa aktif SEMUA profil sekaligus.
        on-login tiap profil cuma memanggil script bersama itu.
        Script lama membuat scheduler terpisah untuk setiap profil. Di router dengan ribuan kartu,
        tiap scheduler menyapu ulang seluruh daftar user. Yang lama bisa dicabut di sini.
      </div>
    </div>
  </div>

  <div id="vsList" style="display:grid;gap:16px">
    @foreach ($routers as $r)
      <div class="card" data-router="{{ $r->slug }}" data-router-nama="{{ $r->name }}">
        <div class="card-head">
          <div>
            <h3>{{ $r->name }}</h3>
            <p class="mono" style="font-size:11.5px">{{ $r->host }}</p>
          </div>
          <div class="ch-actions">
            <span class="badge" data-badge>memeriksa…</span>
          </div>
        </div>
        <div class="card-pad" data-body>
          <div class="skeleton" style="height:12px;width:40%;margin-bottom:8px"></div>
          <div class="skeleton" style="height:12px;width:70%"></div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="mdl-backdrop" id="vsPreview" role="dialog" aria-modal="true" hidden>
    <div class="mdl mdl-xl">
      <div class="mdl-head">
        <div class="mdl-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        </div>
        <div class="grow">
          <h3 class="mdl-title">Isi Script</h3>
          <p class="mdl-sub" id="vsPreviewSub"></p>
        </div>
        <button type="button" class="mdl-close" id="vsPreviewClose" aria-label="Tutup">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="mdl-body">
        <div class="field"><label>on-login (/system script)</label>
          <pre class="vs-code" id="vsPreviewLogin"></pre>
        </div>
        <div class="field" style="margin-top:14px"><label>pengawas masa aktif (/system scheduler)</label>
          <pre class="vs-code" id="vsPreviewSched"></pre>
        </div>
      </div>
      <div class="mdl-foot">
        <span class="grow"></span>
        <button type="button" class="btn btn-sm" id="vsPreviewCancel">Tutup</button>
      </div>
    </div>
  </div>

  @include('vouchers._toast')

  
@endsection

@push('scripts')
<script>
(function () {
  const csrf    = document.querySelector('meta[name="csrf-token"]').content;
  const urlStat = @json(route('voucher-scripts.status',  ['router' => '__SLUG__']));
  const urlPrev = @json(route('voucher-scripts.preview', ['router' => '__SLUG__']));
  const urlInst = @json(route('voucher-scripts.install', ['router' => '__SLUG__']));
  const urlPaket = @json(route('packages.index'));

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

  const cache = {};

  document.querySelectorAll('[data-router]').forEach(muat);

  async function muat(kartu) {
    const slug  = kartu.dataset.router;
    const body  = kartu.querySelector('[data-body]');
    const badge = kartu.querySelector('[data-badge]');

    try {
      const res  = await fetch(urlStat.replace('__SLUG__', slug), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!data.success) throw new Error(data.error || 'Router tidak menjawab.');

      cache[slug] = data;
      gambar(kartu, data);
    } catch (e) {
      badge.className = 'badge err';
      badge.textContent = 'tidak terhubung';
      body.innerHTML = '<div style="color:var(--err);font-size:12.5px">' + esc(e.message) + '</div>';
    }
  }

  function gambar(kartu, d) {
    const badge = kartu.querySelector('[data-badge]');
    const body  = kartu.querySelector('[data-body]');

    const terpasang = d.script.installed && d.script.current && d.scheduler;
    badge.className = 'badge ' + (terpasang ? 'ok' : (d.script.installed ? 'warn' : ''));
    badge.textContent = terpasang ? 'terpasang' : (d.script.installed ? 'perlu diperbarui' : 'belum dipasang');

    let html = '<div class="vs-facts">'
      + '<span>RouterOS <b>' + esc(d.version) + '</b></span>'
      + '<span>Format tanggal <b>' + (d.iso_date ? 'ISO (v7)' : 'mon/dd/yyyy (v6)') + '</b></span>'
      + '<span>Script on-login <b>' + (d.script.installed ? (d.script.current ? 'terbaru' : 'versi lama') : 'belum ada') + '</b></span>'
      + '<span>Scheduler <b>' + (d.scheduler ? esc(d.scheduler.interval) : 'belum ada') + '</b></span>'
      + '</div>';

    if (d.legacy_schedulers.length) {
      html += '<div class="vp-warn">'
        + '<b>' + d.legacy_schedulers.length + ' scheduler lama</b> masih jalan: '
        + esc(d.legacy_schedulers.map(s => s.name + ' (' + s.interval + ')').join(', '))
        + '. Masing-masing menyapu ulang seluruh daftar user, centang "cabut scheduler lama" saat memasang.'
        + '</div>';
    }

    const diawasi = d.profiles.filter(p => !p.locked && p.mode !== 'off');
    const bebas   = d.profiles.filter(p => !p.locked && p.mode === 'off');
    html += '<div class="vs-profil">'
      + '<div><span class="t-mute">Diawasi:</span> ' + (diawasi.length ? diawasi.map(p => '<b>' + esc(p.name) + '</b> ' + esc(p.validity || '')).join(', ') : 'tidak ada') + '</div>'
      + '<div><span class="t-mute">Tidak diawasi:</span> ' + (bebas.length ? bebas.map(p => esc(p.name)).join(', ') : 'tidak ada') + '</div>'
      + '<a class="link-strong" href="' + urlPaket + '?router=' + encodeURIComponent(d.router.slug) + '">Ubah profil di halaman Paket</a>'
      + '</div>';

    html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">'
      + '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-2)">'
        + '<input type="checkbox" data-drop' + (d.legacy_schedulers.length ? ' checked' : '') + '> cabut scheduler lama'
      + '</label>'
      + '<span class="grow"></span>'
      + '<button type="button" class="btn btn-sm" data-preview>Lihat script</button>'
      + '<button type="button" class="btn btn-sm" data-install>Pasang ulang script</button>'
      + '</div>';

    body.innerHTML = html;

    const slug = kartu.dataset.router;
    body.querySelector('[data-preview]').addEventListener('click', () => preview(slug));
    body.querySelector('[data-install]').addEventListener('click', () => pasang(kartu));
  }

  async function pasang(kartu) {
    const slug = kartu.dataset.router;
    const btn  = kartu.querySelector('[data-install]');
    const d    = cache[slug];

    const profiles = d.profiles.filter(p => !p.locked).map(p => ({
      name: p.name, mode: p.mode, validity: p.validity, price: p.price, sprice: p.sprice, record: p.record, lock: p.lock,
    }));

    const diawasi = profiles.filter(p => p.mode !== 'off').map(p => p.name);

    const ok = await window.zeroConfirm({
      title: 'Pasang Ulang Script',
      message: 'Script on-login dan scheduler di router ini ditulis ulang memakai setelan profil yang sekarang. '
        + (diawasi.length ? 'Profil yang diawasi: ' + diawasi.join(', ') + '.' : 'Tidak ada profil yang diawasi. Scheduler akan dicabut.')
        + ' Voucher yang sudah beredar tidak terpengaruh.',
      action: 'Pasang',
      variant: 'warning',
    });

    if (!ok) return;

    btn.disabled = true;
    btn.textContent = 'Memasang…';

    try {
      const res = await fetch(urlInst.replace('__SLUG__', slug), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ profiles: profiles, drop_legacy: kartu.querySelector('[data-drop]').checked }),
      });
      const data = await res.json();

      window.vgToast(data.message || data.error || 'Selesai.', data.success ? 'ok' : 'err');
      if (data.success) muat(kartu);
    } catch (e) {
      window.vgToast('Gagal menghubungi server: ' + e.message, 'err');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Pasang ulang script';
    }
  }

  const prev = document.getElementById('vsPreview');

  async function preview(slug) {
    document.getElementById('vsPreviewSub').textContent = 'Router ' + slug + ': memuat…';
    document.getElementById('vsPreviewLogin').textContent = '';
    document.getElementById('vsPreviewSched').textContent = '';
    prev.hidden = false;
    requestAnimationFrame(() => prev.classList.add('is-open'));

    try {
      const res  = await fetch(urlPrev.replace('__SLUG__', slug), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!data.success) throw new Error(data.error);

      document.getElementById('vsPreviewSub').textContent = 'Yang akan ditulis ke router ' + slug + '.';
      document.getElementById('vsPreviewLogin').textContent = data.login;
      document.getElementById('vsPreviewSched').textContent = data.scheduler;
    } catch (e) {
      document.getElementById('vsPreviewSub').textContent = e.message;
    }
  }

  function tutupPreview() {
    prev.classList.remove('is-open');
    setTimeout(() => { prev.hidden = true; }, 180);
  }

  document.getElementById('vsPreviewClose').addEventListener('click', tutupPreview);
  document.getElementById('vsPreviewCancel').addEventListener('click', tutupPreview);
  prev.addEventListener('click', e => { if (e.target === prev) tutupPreview(); });
})();
</script>
@endpush


<div class="mdl-backdrop" id="vgBackdrop" role="dialog" aria-modal="true" aria-labelledby="vgTitle" hidden>
  <div class="mdl mdl-lg">

    <div class="mdl-head">
      <div class="mdl-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M13 5v14" stroke-dasharray="2 3"/></svg>
      </div>
      <div class="grow min0">
        <h3 class="mdl-title" id="vgTitle">Buat Voucher</h3>
        <p class="mdl-sub" id="vgSub">Kartu ditulis ke router sekaligus disimpan di panel.</p>
      </div>
      <button type="button" class="mdl-close" id="vgClose" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form class="mdl-body" id="vgForm" autocomplete="off">
      <div class="mdl-alert" id="vgAlert" hidden></div>

      <div class="mdl-grid">
        <div class="field">
          <label>Router <span class="req">*</span></label>
          <select name="router" class="select" id="vgRouter">
            <option value="">(pilih router)</option>
            @foreach ($routers as $r)
              <option value="{{ $r->slug }}">{{ $r->name }}</option>
            @endforeach
          </select>
          <small class="mdl-err" data-err="router"></small>
        </div>

        <div class="field">
          <label>Profil voucher <span class="req">*</span></label>
          <select name="profile" class="select" id="vgProfile" disabled>
            <option value="">(pilih router dulu)</option>
          </select>
          <small class="mdl-hint" id="vgProfileHint"></small>
          <small class="mdl-err" data-err="profile"></small>
        </div>

        <div class="field">
          <label>Server hotspot</label>
          <select name="server" class="select" id="vgServer" disabled>
            <option value="">all</option>
          </select>
          <small class="mdl-err" data-err="server"></small>
        </div>

        <div class="field">
          <label>Jumlah voucher <span class="req">*</span></label>
          <input type="number" name="quantity" class="input mono" id="vgQty"
                 value="{{ $defaultQty }}" min="1" max="{{ $maxPerBatch }}">
          <small class="mdl-hint">Maksimal {{ number_format($maxPerBatch, 0, ',', '.') }} kartu sekali buat.</small>
          <small class="mdl-err" data-err="quantity"></small>
        </div>

        <div class="field">
          <label>Bentuk kartu</label>
          <select name="mode" class="select" id="vgMode">
            <option value="vc">Satu kode (username = password)</option>
            <option value="up">Username + password terpisah</option>
          </select>
        </div>

        <div class="field">
          <label>Jenis karakter</label>
          <select name="charset" class="select" id="vgCharset">
            @foreach ($charsets as $key => $cs)
              <option value="{{ $key }}" @selected($key === 'lower')>{{ $cs['label'] }}</option>
            @endforeach
          </select>
        </div>

        <div class="field">
          <label>Panjang kode</label>
          <input type="number" name="code_length" class="input mono" id="vgLen" value="4" min="3" max="12">
          <small class="mdl-hint" id="vgSpaceHint"></small>
          <small class="mdl-err" data-err="code_length"></small>
        </div>

        <div class="field" id="vgPassWrap" hidden>
          <label>Panjang password</label>
          <input type="number" name="password_length" class="input mono" id="vgPassLen" value="4" min="3" max="8">
          <small class="mdl-hint">Password selalu angka, biar mudah dibacakan.</small>
          <small class="mdl-err" data-err="password_length"></small>
        </div>

        <div class="field">
          <label>Prefix</label>
          <input type="text" name="prefix" class="input mono" maxlength="10" placeholder="mis. 5K">
          <small class="mdl-err" data-err="prefix"></small>
        </div>

        <div class="field">
          <label>Suffix</label>
          <input type="text" name="suffix" class="input mono" maxlength="10" placeholder="kosongkan kalau tidak perlu">
          <small class="mdl-err" data-err="suffix"></small>
        </div>

        <div class="field">
          <label>Jatah jam (limit uptime)</label>
          <input type="text" name="limit_uptime" class="input mono" id="vgUptime" placeholder="mis. 5h">
          <small class="mdl-hint" id="vgUptimeHint">Kosongkan = tanpa batas jam, hanya masa aktif yang berlaku.</small>
          <small class="mdl-err" data-err="limit_uptime"></small>
        </div>

        <div class="field">
          <label>Kuota data (MB)</label>
          <input type="number" name="limit_mb" class="input mono" min="0" placeholder="kosongkan = tanpa kuota">
          <small class="mdl-err" data-err="limit_mb"></small>
        </div>

        <div class="field span-all">
          <label>Catatan batch</label>
          <input type="text" name="label" class="input" maxlength="20" placeholder="mis. PROMO, WARUNG BU ANI">
          <small class="mdl-hint">Ikut ditulis sebagai comment di router: <span class="mono" id="vgCommentPreview">vc-###-{{ date('m.d.y') }}-</span></small>
          <small class="mdl-err" data-err="label"></small>
        </div>
      </div>
    </form>

    <div class="mdl-body" id="vgProgress" hidden>
      <div class="vg-step" id="vgStep1">
        <span class="vg-dot"></span>
        <div>
          <b>Membuat kode…</b>
          <span id="vgStep1Sub">Mengadu kode baru dengan kartu lama di router dan database.</span>
        </div>
      </div>

      <div class="vg-step" id="vgStep2">
        <span class="vg-dot"></span>
        <div>
          <b>Menulis ke router…</b>
          <span id="vgStep2Sub">Menunggu antrean.</span>
        </div>
      </div>

      <div class="progress" style="margin-top:16px"><i id="vgBar" style="width:0%"></i></div>
      <div class="vg-count" id="vgCount">0/0 kartu</div>

      <div class="vg-note" id="vgNote">
        Kartu sudah tersimpan di panel dan bisa langsung dicetak. Kalau router
        sedang mati, sisanya dikirim otomatis begitu router hidup lagi.
      </div>
    </div>

    <div class="mdl-foot">
      <span class="vg-foot-info" id="vgFootInfo"></span>
      <span class="grow"></span>
      <button type="button" class="btn btn-sm" id="vgCancel">Batal</button>
      <button type="button" class="btn btn-sm btn-primary" id="vgSubmit">Generate</button>
      <a href="#" class="btn btn-sm btn-primary" id="vgOpenBatch" hidden>Lihat &amp; cetak</a>
    </div>

  </div>
</div>


@push('scripts')
<script>
(function () {
  const backdrop = document.getElementById('vgBackdrop');
  if (!backdrop) return;

  const form     = document.getElementById('vgForm');
  const progress = document.getElementById('vgProgress');
  const submit   = document.getElementById('vgSubmit');
  const cancel   = document.getElementById('vgCancel');
  const openBtn  = document.getElementById('vgOpen');
  const openBatch = document.getElementById('vgOpenBatch');
  const alertBox = document.getElementById('vgAlert');
  const routerEl = document.getElementById('vgRouter');
  const profEl   = document.getElementById('vgProfile');
  const serverEl = document.getElementById('vgServer');
  const modeEl   = document.getElementById('vgMode');
  const passWrap = document.getElementById('vgPassWrap');
  const lenEl    = document.getElementById('vgLen');
  const charEl   = document.getElementById('vgCharset');
  const qtyEl    = document.getElementById('vgQty');
  const uptimeEl = document.getElementById('vgUptime');
  const passLen  = document.getElementById('vgPassLen');

  const urlStore   = @json(route('vouchers.store'));
  const urlOptions = @json(route('vouchers.router-options', ['router' => '__SLUG__']));
  const csrf       = document.querySelector('meta[name="csrf-token"]').content;
  const charsets   = @json(collect($charsets)->map(fn ($c) => strlen($c['chars'])));

  let profil = [];
  let polling = null;

  function open() {
    backdrop.hidden = false;
    requestAnimationFrame(() => backdrop.classList.add('is-open'));
    setTimeout(() => routerEl.focus(), 120);
  }

  function close() {
    if (submit.dataset.busy === '1') return;
    backdrop.classList.remove('is-open');
    setTimeout(() => { backdrop.hidden = true; reset(); }, 180);
  }

  function reset() {
    if (polling) { clearInterval(polling); polling = null; }
    form.inert = false;
    bersihkanError();
    form.hidden = false;
    progress.hidden = true;
    submit.hidden = false;
    submit.disabled = false;
    submit.textContent = 'Generate';
    submit.dataset.busy = '';
    cancel.textContent = 'Batal';
    openBatch.hidden = true;
    alertBox.hidden = true;
    document.getElementById('vgFootInfo').textContent = '';
    ['vgStep1', 'vgStep2'].forEach(id => document.getElementById(id).className = 'vg-step');
  }

  openBtn && openBtn.addEventListener('click', open);
  document.getElementById('vgClose').addEventListener('click', close);
  cancel.addEventListener('click', close);
  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !backdrop.hidden) close(); });

  routerEl.addEventListener('change', async function () {
    profEl.innerHTML = '<option value="">memuat…</option>';
    profEl.disabled = true;
    serverEl.disabled = true;
    document.getElementById('vgProfileHint').textContent = '';

    if (!routerEl.value) {
      profEl.innerHTML = '<option value="">(pilih router dulu)</option>';
      return;
    }

    try {
      const res  = await fetch(urlOptions.replace('__SLUG__', routerEl.value), { headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!data.success) throw new Error(data.error || 'Gagal membaca profil router.');

      profil = data.profiles;
      profEl.innerHTML = '<option value="">(pilih profil)</option>';

      data.profiles.forEach(function (p) {
        const o = document.createElement('option');
        o.value = p.name;
        o.textContent = p.name + (p.validity ? ' (masa ' + p.validity + ')' : ' (bukan profil voucher)');
        profEl.appendChild(o);
      });

      serverEl.innerHTML = '<option value="">all</option>';
      (data.servers || []).forEach(function (s) {
        const o = document.createElement('option');
        o.value = s; o.textContent = s;
        serverEl.appendChild(o);
      });

      profEl.disabled = false;
      serverEl.disabled = false;
    } catch (e) {
      profEl.innerHTML = '<option value="">gagal memuat</option>';
      tampilkanAlert(e.message);
    }
  });

  profEl.addEventListener('change', function () {
    const p = profil.find(x => x.name === profEl.value);
    const hint = document.getElementById('vgProfileHint');

    if (!p) { hint.textContent = ''; return; }

    const bagian = [];
    if (p.validity) bagian.push('masa aktif ' + p.validity + ' sejak login pertama');
    if (p.price)    bagian.push('Rp ' + Number(p.price).toLocaleString('id-ID'));
    if (p.rate)     bagian.push(p.rate);
    if (!p.managed && p.expmode) bagian.push('masih memakai script lama');
    if (!p.validity) bagian.push('profil ini belum punya masa aktif (kartu tidak akan pernah hangus)');

    hint.textContent = bagian.join(' · ');

    if (p.limit && !uptimeEl.value) uptimeEl.value = p.limit;
  });

  modeEl.addEventListener('change', function () {
    passWrap.hidden = modeEl.value !== 'up';
    document.getElementById('vgCommentPreview').textContent =
      modeEl.value + '-###-' + @json(date('m.d.y')) + '-';
  });

  function hitungRuang() {
    const basis = charsets[charEl.value] || 0;
    const n = Math.min(parseInt(lenEl.value || '0', 10), 12);
    const qty = parseInt(qtyEl.value || '0', 10);
    const ekor = modeEl.value === 'vc' && ['lower', 'upper', 'upplow'].includes(charEl.value) ? Math.floor(n / 2) : 0;
    const ruang = Math.pow(basis, n - ekor) * Math.pow(charsets.num || 8, ekor);
    const el = document.getElementById('vgSpaceHint');

    if (!basis || !n) { el.textContent = ''; return; }

    el.textContent = ruang.toLocaleString('id-ID') + ' kombinasi' + (ekor ? ', ' + ekor + ' angka di belakang' : '');
    el.style.color = (qty > 0 && ruang < qty * 4) ? 'var(--err)' : '';
    if (qty > 0 && ruang < qty * 4) el.textContent += ' (terlalu sedikit, tambah panjang kode)';
  }
  [lenEl, charEl, qtyEl, modeEl].forEach(el => el.addEventListener('input', hitungRuang));
  hitungRuang();

  passLen.addEventListener('input', () => { passLen.dataset.manual = '1'; });
  lenEl.addEventListener('input', function () {
    if (passLen.dataset.manual === '1') return;
    const n = parseInt(lenEl.value || '0', 10);
    if (n) passLen.value = Math.min(8, Math.max(3, n));
  });

  function tampilkanAlert(pesan) {
    alertBox.hidden = false;
    alertBox.textContent = pesan;
  }

  function bersihkanError() {
    alertBox.hidden = true;
    form.querySelectorAll('.mdl-err').forEach(el => { el.textContent = ''; el.classList.remove('on'); });
    form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
  }

  function tandai(errors) {
    let pertama = null;
    Object.entries(errors).forEach(([key, pesan]) => {
      const el = form.querySelector('[data-err="' + key + '"]');
      if (el) { el.textContent = pesan; el.classList.add('on'); }
      const isian = form.elements[key];
      if (isian && isian.setAttribute) {
        isian.setAttribute('aria-invalid', 'true');
        if (!pertama && !isian.disabled) pertama = isian;
      }
    });
    tampilkanAlert('Periksa lagi isian yang ditandai merah.');
    if (pertama) pertama.focus();
  }

  function periksa(d) {
    const e = {};
    const bulat = v => /^\d+$/.test(String(v).trim());
    const maks = parseInt(qtyEl.max, 10);

    if (!d.router) e.router = 'Pilih router tujuan dulu.';
    else if (!d.profile) e.profile = profEl.disabled ? 'Profil router belum termuat, pilih ulang routernya.' : 'Pilih profil voucher dulu.';

    if (String(d.quantity).trim() === '') e.quantity = 'Jumlah voucher wajib diisi.';
    else if (!bulat(d.quantity) || +d.quantity < 1) e.quantity = 'Jumlah voucher minimal 1.';
    else if (+d.quantity > maks) e.quantity = 'Maksimal ' + maks.toLocaleString('id-ID') + ' voucher sekali buat.';

    if (!bulat(d.code_length) || +d.code_length < 3) e.code_length = 'Panjang kode minimal 3 karakter.';
    else if (+d.code_length > 12) e.code_length = 'Panjang kode maksimal 12 karakter.';

    if (d.mode === 'up' && (!bulat(d.password_length) || +d.password_length < 3 || +d.password_length > 8)) {
      e.password_length = 'Panjang password 3 sampai 8 angka.';
    }

    if (!/^[A-Za-z0-9]*$/.test(d.prefix || '')) e.prefix = 'Prefix hanya boleh huruf dan angka.';
    if (!/^[A-Za-z0-9]*$/.test(d.suffix || '')) e.suffix = 'Suffix hanya boleh huruf dan angka.';
    if (d.limit_uptime && !/^(\d+[wdhms])+$/.test(d.limit_uptime.trim())) e.limit_uptime = 'Format jatah jam salah. Contoh: 5h, 1d, 1h30m.';
    if (d.limit_mb && (!bulat(d.limit_mb) || +d.limit_mb > 1048576)) e.limit_mb = 'Kuota berupa angka MB, maksimal 1048576.';

    return e;
  }

  form.addEventListener('input', function (ev) {
    const nama = ev.target.name;
    const el = nama && form.querySelector('[data-err="' + nama + '"]');
    if (el && el.classList.contains('on')) { el.textContent = ''; el.classList.remove('on'); ev.target.removeAttribute('aria-invalid'); }
  });
  form.addEventListener('change', function (ev) {
    if (ev.target === routerEl) ['router', 'profile'].forEach(k => {
      const el = form.querySelector('[data-err="' + k + '"]');
      if (el) { el.textContent = ''; el.classList.remove('on'); }
      form.elements[k].removeAttribute('aria-invalid');
    });
    if (ev.target === profEl) {
      const el = form.querySelector('[data-err="profile"]');
      el.textContent = ''; el.classList.remove('on'); profEl.removeAttribute('aria-invalid');
    }
  });

  submit.addEventListener('click', async function () {
    if (submit.dataset.busy === '1') return;
    bersihkanError();

    const payload = {};
    new FormData(form).forEach((v, k) => { payload[k] = v; });

    const salah = periksa(payload);
    if (Object.keys(salah).length) { tandai(salah); return; }

    submit.disabled = true;
    submit.dataset.busy = '1';
    submit.textContent = 'Memproses…';
    form.inert = true;
    document.getElementById('vgFootInfo').textContent = 'Membuat ' + (+payload.quantity).toLocaleString('id-ID') + ' kode…';

    try {
      const res  = await fetch(urlStore, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await res.json();

      if (!res.ok) {
        gagal(data, res.status);
        return;
      }

      form.inert = false;
      form.hidden = true;
      progress.hidden = false;
      document.getElementById('vgFootInfo').textContent = '';
      document.getElementById('vgStep1').className = 'vg-step done';
      document.getElementById('vgStep1Sub').textContent = payload.quantity + ' kode dibuat dan disimpan di panel.';
      document.getElementById('vgStep2').className = 'vg-step on';

      openBatch.href = data.redirect;
      pantau(data.progress, parseInt(payload.quantity, 10));
    } catch (e) {
      gagal({ error: 'Gagal menghubungi server: ' + e.message }, 500);
    }
  });

  function gagal(data, status) {
    form.inert = false;
    form.hidden = false;
    progress.hidden = true;
    submit.disabled = false;
    submit.dataset.busy = '';
    submit.textContent = 'Generate';
    document.getElementById('vgFootInfo').textContent = '';
    document.getElementById('vgStep1').className = 'vg-step';

    if (status === 422 && data.errors) {
      tandai(Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0]])));
      return;
    }

    tampilkanAlert(data.error || 'Gagal membuat voucher.');
  }

  function pantau(url, total) {
    const bar   = document.getElementById('vgBar');
    const count = document.getElementById('vgCount');
    const sub   = document.getElementById('vgStep2Sub');

    count.textContent = '0/' + total + ' kartu';

    const tarik = async function () {
      try {
        const res  = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();

        bar.style.width = data.progress + '%';
        count.textContent = data.synced + '/' + data.quantity + ' kartu masuk router'
          + (data.failed ? ' · ' + data.failed + ' gagal' : '');

        if (data.status === 'syncing')  sub.textContent = 'Sedang ditulis…';
        if (data.status === 'pending')  sub.textContent = 'Menunggu antrean…';

        if (!data.done) return;

        clearInterval(polling); polling = null;

        const step2 = document.getElementById('vgStep2');
        step2.className = 'vg-step ' + (data.status === 'success' ? 'done' : 'err');
        sub.textContent = data.status === 'success'
          ? 'Semua kartu sudah ada di router.'
          : (data.error || data.failed + ' kartu gagal ditulis. Bisa disinkron ulang dari halaman batch.');

        bar.parentElement.classList.toggle('err', data.status === 'failed');
        bar.parentElement.classList.toggle('warn', data.status === 'partial');

        submit.hidden = true;
        submit.dataset.busy = '';
        openBatch.hidden = false;
        cancel.textContent = 'Tutup';
      } catch (e) {}
    };

    tarik();
    polling = setInterval(tarik, 1500);
  }
})();
</script>
@endpush

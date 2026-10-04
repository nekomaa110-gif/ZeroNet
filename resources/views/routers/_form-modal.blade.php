
<div class="mdl-backdrop" id="rmBackdrop" role="dialog" aria-modal="true" aria-labelledby="rmTitle" hidden>
  <div class="mdl">

    <div class="mdl-head">
      <div class="mdl-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M15 10v4"/><path d="M17.84 7.17a4 4 0 0 0-5.66 0"/></svg>
      </div>
      <div class="grow min0">
        <h3 class="mdl-title" id="rmTitle">Tambah Router</h3>
        <p class="mdl-sub" id="rmSub"></p>
      </div>
      <button type="button" class="mdl-close" id="rmClose" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form class="mdl-body" id="rmForm" autocomplete="off">
      <div class="mdl-alert" id="rmAlert" hidden></div>

      <div class="mdl-grid">
        <div class="field span-all">
          <label>Nama Router <span class="req">*</span></label>
          <input type="text" name="name" class="input" placeholder="Mikrotik" maxlength="60">
          <small class="mdl-err" data-err="name"></small>
        </div>

        <div class="field">
          <label>IP<span class="req">*</span></label>
          <input type="text" name="host" class="input mono" placeholder="0.0.0.0" maxlength="100">
          <small class="mdl-err" data-err="host"></small>
        </div>

        <div class="field">
          <label>Port API <span class="req">*</span></label>
          <input type="number" name="port" class="input mono" value="8728" min="1" max="65535">
          <small class="mdl-err" data-err="port"></small>
        </div>

        <div class="field">
          <label>Username API <span class="req">*</span></label>
          <input type="text" name="username" class="input mono" placeholder="admin" maxlength="64" autocomplete="off">
          <small class="mdl-err" data-err="username"></small>
        </div>

        <div class="field">
          <label>Password <span class="req" id="rmPassReq">*</span></label>
          <input type="password" name="password" class="input" placeholder="••••••••" maxlength="128" autocomplete="new-password">
          <small class="mdl-err" data-err="password"></small>
          <small class="mdl-hint" id="rmPassHint" hidden>Kosongkan kalau tidak ingin mengganti password.</small>
        </div>

        <div class="field">
          <label>Interface WAN</label>
          <input type="text" name="wan_interface" class="input mono" value="ether1" list="rmIfaceList" maxlength="32">
          <datalist id="rmIfaceList"></datalist>
          <small class="mdl-err" data-err="wan_interface"></small>
        </div>

        <div class="field">
          <label>Timeout (detik)</label>
          <input type="number" name="timeout" class="input mono" value="5" min="1" max="30">
          <small class="mdl-err" data-err="timeout"></small>
        </div>
      </div>

      <div class="rm-test" id="rmTest" hidden></div>
    </form>

    <div class="mdl-foot">
      <button type="button" class="btn btn-sm" id="rmTestBtn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        Tes Koneksi
      </button>
      <span class="grow"></span>
      <button type="button" class="btn btn-sm" id="rmCancel">Batal</button>
      <button type="button" class="btn btn-sm btn-primary" id="rmSave">Simpan</button>
    </div>

  </div>
</div>


<script>
(function () {
  'use strict';

  var backdrop = document.getElementById('rmBackdrop');
  if (!backdrop) return;

  var URLS = {
    store:  @json(route('routers.store')),
    test:   @json(route('routers.test-connection')),
    update: @json(route('routers.update', '__SLUG__')),
    remove: @json(route('routers.destroy', '__SLUG__')),
  };

  var form   = document.getElementById('rmForm'),
      elTitle= document.getElementById('rmTitle'),
      elSub  = document.getElementById('rmSub'),
      elAlert= document.getElementById('rmAlert'),
      elTest = document.getElementById('rmTest'),
      elList = document.getElementById('rmIfaceList'),
      passReq= document.getElementById('rmPassReq'),
      passHnt= document.getElementById('rmPassHint'),
      btnTest= document.getElementById('rmTestBtn'),
      btnSave= document.getElementById('rmSave');

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var slug = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function field(name) { return form.elements[name]; }

  function clearErrors() {
    elAlert.hidden = true; elAlert.textContent = '';
    form.querySelectorAll('.mdl-err').forEach(function (el) { el.classList.remove('on'); el.textContent = ''; });
  }

  function showErrors(res) {
    var json  = res.json || {};
    var errs  = json.errors || null;
    var stray = [];

    if (errs) {
      Object.keys(errs).forEach(function (k) {
        var el = form.querySelector('[data-err="' + k + '"]');
        if (el) { el.textContent = errs[k][0]; el.classList.add('on'); }
        else stray.push(errs[k][0]);
      });
    }

    var msg = res.status === 419 ? 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.'
            : stray.length      ? stray.join(' ')
            : errs              ? ''
            : (json.error || json.message || 'Terjadi kesalahan.');

    if (msg) { elAlert.textContent = msg; elAlert.hidden = false; }
  }

  function payload() {
    return {
      name:          field('name').value.trim(),
      host:          field('host').value.trim(),
      port:          field('port').value,
      username:      field('username').value.trim(),
      password:      field('password').value,
      wan_interface: field('wan_interface').value.trim(),
      timeout:       field('timeout').value,
      router:        slug || '',
    };
  }

  async function post(url, method, body) {
    var res = await fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    });
    var json = {};
    try { json = await res.json(); } catch (e) {}
    return { ok: res.ok, status: res.status, json: json };
  }

  function busy(state) {
    btnSave.disabled = btnTest.disabled = state;
  }

  async function test() {
    clearErrors();
    busy(true);
    elTest.hidden = false;
    elTest.className = 'rm-test wait';
    elTest.innerHTML = '<span class="spin-ring"></span><div>Menghubungi router…</div>';

    var r = await post(URLS.test, 'POST', payload());
    busy(false);

    if (!r.ok || !r.json.success) {
      elTest.className = 'rm-test err';
      elTest.innerHTML =
        '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
        '<div><b>Gagal terhubung</b>' + esc(r.json.error || r.json.message || 'Router tidak merespons.') + '</div>';
      if (r.json.errors) showErrors(r);
      return false;
    }

    var i = r.json.info || {};
    elTest.className = 'rm-test ok';
    elTest.innerHTML =
      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +
      '<div><b>Terhubung (' + esc(i.identity || '-') + ')</b>' +
      esc((i.board || '-') + ' · ROS ' + (i.version || '-') + ' · uptime ' + (i.uptime || '-')) + '</div>';

    var ifaces = r.json.interfaces || [];
    elList.innerHTML = ifaces.map(function (n) { return '<option value="' + esc(n) + '">'; }).join('');
    if (ifaces.length && ifaces.indexOf(field('wan_interface').value.trim()) === -1) {
      field('wan_interface').value = ifaces[0];
    }
    return true;
  }

  async function save() {
    clearErrors();
    busy(true);

    var r = slug
      ? await post(URLS.update.replace('__SLUG__', encodeURIComponent(slug)), 'PUT', payload())
      : await post(URLS.store, 'POST', payload());

    busy(false);

    if (!r.ok || !r.json.success) { showErrors(r); return; }
    if (!slug && r.json.redirect) { location.href = r.json.redirect; return; }
    location.reload();
  }

  function open(data) {
    data = data || {};
    slug = data.slug || null;

    clearErrors();
    elTest.hidden = true;
    elList.innerHTML = '';

    elTitle.textContent = slug ? 'Edit Router' : 'Tambah Router';
    elSub.textContent   = '';

    field('name').value          = data.name || '';
    field('host').value          = data.host || '';
    field('port').value          = data.port || 8728;
    field('username').value      = data.user || '';
    field('password').value      = '';
    field('wan_interface').value = data.wan || 'ether1';
    field('timeout').value       = data.timeout || 5;

    passReq.hidden = !!slug;
    passHnt.hidden = !slug;
    btnSave.textContent = slug ? 'Simpan Perubahan' : 'Tambah Router';

    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    setTimeout(function () { field('name').focus(); }, 120);
  }

  function close() {
    backdrop.classList.remove('is-open');
    setTimeout(function () { backdrop.hidden = true; }, 180);
  }

  document.getElementById('rmClose').addEventListener('click', close);
  document.getElementById('rmCancel').addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) close();
  });

  btnTest.addEventListener('click', test);
  btnSave.addEventListener('click', save);
  form.addEventListener('submit', function (e) { e.preventDefault(); save(); });

  document.querySelectorAll('[data-router-add]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); open(); });
  });

  document.querySelectorAll('[data-router-edit]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      open({
        slug:    el.dataset.slug,
        name:    el.dataset.name,
        host:    el.dataset.host,
        port:    el.dataset.port,
        user:    el.dataset.user,
        wan:     el.dataset.wan,
        timeout: el.dataset.timeout,
      });
    });
  });

  document.querySelectorAll('[data-router-delete]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var name = el.dataset.name || 'router ini';
      window.zeroConfirm({
        title: 'Hapus Router',
        message: 'Router ' + name + ' akan dihapus dari panel beserta kredensial APInya. Konfigurasi di perangkat MikroTik sendiri tidak ikut terhapus.',
        action: 'Hapus Router',
        variant: 'danger',
      }).then(async function (ok) {
        if (!ok) return;
        var r = await post(URLS.remove.replace('__SLUG__', encodeURIComponent(el.dataset.slug)), 'DELETE', {});
        if (r.ok && r.json.success) location.reload();
        else window.vgToast(r.json.message || r.json.error || 'Gagal menghapus router.', 'err');
      });
    });
  });
})();
</script>

@include('vouchers._toast')

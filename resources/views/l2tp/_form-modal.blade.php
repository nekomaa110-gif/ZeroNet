
<div class="mdl-backdrop" id="ltBackdrop" role="dialog" aria-modal="true" aria-labelledby="ltTitle" hidden>
  <div class="mdl">

    <div class="mdl-head">
      <div class="mdl-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div class="grow min0">
        <h3 class="mdl-title" id="ltTitle">Tambah Akun L2TP</h3>
        <p class="mdl-sub" id="ltSub"></p>
      </div>
      <button type="button" class="mdl-close" id="ltClose" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form class="mdl-body" id="ltForm" autocomplete="off">
      <div class="mdl-alert" id="ltAlert" hidden></div>

      <div class="mdl-grid">
        <div class="field span-all">
          <label>Username <span class="req">*</span></label>
          <input type="text" name="username" class="input mono" placeholder="mikrotik-div1" maxlength="32" autocomplete="off">
          <small class="mdl-err" data-err="username"></small>
        </div>

        <div class="field">
          <label>Password <span class="req" id="ltPassReq">*</span></label>
          <input type="text" name="secret" class="input mono" placeholder="••••••••" maxlength="64" autocomplete="off">
          <small class="mdl-err" data-err="secret"></small>
          <small class="mdl-hint" id="ltPassHint" hidden>Kosongkan kalau tidak ingin mengganti password.</small>
        </div>

        <div class="field">
          <label>IP Tunnel <span class="req">*</span></label>
          <input type="text" name="remote_ip" class="input mono" placeholder="{{ $suggestedIp ?? substr((string) config('l2tp.local_ip'), 0, strrpos((string) config('l2tp.local_ip'), '.') + 1).'x' }}" list="ltIpList" maxlength="15">
          <datalist id="ltIpList">
            @foreach ($pool['free'] as $ip)
              <option value="{{ $ip }}">
            @endforeach
          </datalist>
          <small class="mdl-err" data-err="remote_ip"></small>
          <small class="mdl-hint">Range {{ $pool['start'] }}–{{ $pool['end'] }}. Tiap akun IP-nya harus beda.</small>
        </div>

        <div class="field span-all">
          <label>Catatan</label>
          <input type="text" name="note" class="input" placeholder="mis. Router Divisi 1" maxlength="100">
          <small class="mdl-err" data-err="note"></small>
        </div>
      </div>
    </form>

    <div class="mdl-foot">
      <span class="grow"></span>
      <button type="button" class="btn btn-sm" id="ltCancel">Batal</button>
      <button type="button" class="btn btn-sm btn-primary" id="ltSave">Simpan</button>
    </div>

  </div>
</div>


<script>
(function () {
  'use strict';

  var backdrop = document.getElementById('ltBackdrop');
  if (!backdrop) return;

  var URLS = {
    store:  @json(route('l2tp.store')),
    update: @json(route('l2tp.update', '__ID__')),
    toggle: @json(route('l2tp.toggle', '__ID__')),
    remove: @json(route('l2tp.destroy', '__ID__')),
  };
  var SUGGESTED_IP = @json($suggestedIp ?? '');

  var form    = document.getElementById('ltForm'),
      elTitle = document.getElementById('ltTitle'),
      elSub   = document.getElementById('ltSub'),
      elAlert = document.getElementById('ltAlert'),
      passReq = document.getElementById('ltPassReq'),
      passHnt = document.getElementById('ltPassHint'),
      btnSave = document.getElementById('ltSave');

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var id = null;

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
            : (json.message || 'Terjadi kesalahan.');

    if (msg) { elAlert.textContent = msg; elAlert.hidden = false; }
  }

  function payload() {
    return {
      username:  field('username').value.trim(),
      secret:    field('secret').value.trim(),
      remote_ip: field('remote_ip').value.trim(),
      note:      field('note').value.trim(),
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

  async function save() {
    clearErrors();
    btnSave.disabled = true;

    var r = id
      ? await post(URLS.update.replace('__ID__', id), 'PUT', payload())
      : await post(URLS.store, 'POST', payload());

    btnSave.disabled = false;

    if (!r.ok || !r.json.success) { showErrors(r); return; }
    location.reload();
  }

  function open(data) {
    data = data || {};
    id = data.id || null;

    clearErrors();

    elTitle.textContent = id ? 'Edit Akun L2TP' : 'Tambah Akun L2TP';
    elSub.textContent   = '';

    field('username').value  = data.username || '';
    field('secret').value    = '';
    field('remote_ip').value = data.ip || SUGGESTED_IP || '';
    field('note').value      = data.note || '';

    passReq.hidden = !!id;
    passHnt.hidden = !id;
    btnSave.textContent = id ? 'Simpan Perubahan' : 'Tambah Akun';

    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    setTimeout(function () { field('username').focus(); }, 120);
  }

  function close() {
    backdrop.classList.remove('is-open');
    setTimeout(function () { backdrop.hidden = true; }, 180);
  }

  document.getElementById('ltClose').addEventListener('click', close);
  document.getElementById('ltCancel').addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) close();
  });

  btnSave.addEventListener('click', save);
  form.addEventListener('submit', function (e) { e.preventDefault(); save(); });

  document.querySelectorAll('[data-l2tp-add]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); open(); });
  });

  document.querySelectorAll('[data-l2tp-edit]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      open({
        id:       el.dataset.id,
        username: el.dataset.username,
        ip:       el.dataset.ip,
        note:     el.dataset.note,
      });
    });
  });

  document.querySelectorAll('[data-l2tp-toggle]').forEach(function (el) {
    el.addEventListener('click', async function (e) {
      e.preventDefault();
      el.disabled = true;
      var r = await post(URLS.toggle.replace('__ID__', el.dataset.id), 'PATCH', {});
      el.disabled = false;
      if (r.ok && r.json.success) location.reload();
      else window.vgToast(r.json.message || 'Gagal mengubah status akun.', 'err');
    });
  });

  document.querySelectorAll('[data-l2tp-delete]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      e.preventDefault();
      var name = el.dataset.name || 'akun ini';
      var doDelete = function () {
        post(URLS.remove.replace('__ID__', el.dataset.id), 'DELETE', {}).then(function (r) {
          if (r.ok && r.json.success) location.reload();
          else window.vgToast(r.json.message || 'Gagal menghapus akun.', 'err');
        });
      };
      window.zeroConfirm({
        title: 'Hapus Akun L2TP',
        message: 'Akun "' + name + '" akan dihapus dan langsung hilang dari server (chap-secrets). MikroTik yang memakainya tidak akan bisa connect lagi.',
        action: 'Hapus Akun',
        variant: 'danger',
      }).then(function (ok) { if (ok) doDelete(); });
    });
  });
})();
</script>

@include('vouchers._toast')

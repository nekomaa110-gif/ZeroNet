
<div class="mdl-backdrop" id="csBackdrop" role="dialog" aria-modal="true" aria-labelledby="csTitle" hidden>
  <div class="mdl mdl-sm">

    <div class="mdl-head">
      <div class="mdl-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
      <div class="mdl-title-wrap">
        <h3 class="mdl-title" id="csTitle">Hasil Pencarian</h3>
        <p class="mdl-sub" id="csSub"></p>
      </div>
      <button type="button" class="mdl-close" id="csClose" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="mdl-body" id="csBody">
      <div class="cs-loading"><span class="spin-ring"></span> Mencari</div>
    </div>

  </div>
</div>


<script>
(function () {
  var form = document.getElementById('csForm');
  if (!form) return;

  var input    = document.getElementById('csInput'),
      backdrop = document.getElementById('csBackdrop'),
      body     = document.getElementById('csBody'),
      sub      = document.getElementById('csSub'),
      closeBtn = document.getElementById('csClose'),
      URL_SEARCH  = @json(route('whatsapp.contacts.search')),
      URL_UPDATE  = @json(route('whatsapp.contacts.update', ['contact' => '__ID__'])),
      URL_DESTROY = @json(route('whatsapp.contacts.destroy', ['contact' => '__ID__'])),
      TOKEN = (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
      lastFocus = null,
      lastQuery = '',
      reqSeq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function open() {
    lastFocus = document.activeElement;
    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    closeBtn.focus();
  }

  function close() {
    backdrop.classList.remove('is-open');
    setTimeout(function () { backdrop.hidden = true; }, 160);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function note(text) {
    body.innerHTML = '<div class="cs-empty">' + esc(text) + '</div>';
  }

  function countLabel(n) {
    sub.textContent = n + ' kontak cocok dengan “' + lastQuery + '”';
  }

  function recount() {
    var n = body.querySelectorAll('.cs-item').length;
    if (!n) return note('Kontak sudah tidak ada. Cari lagi kalau perlu.');
    countLabel(n);
  }

  function row(label, value, muted) {
    return '<dt>' + label + '</dt><dd class="' + (muted ? 'cs-muted' : '') + '">' + value + '</dd>';
  }

  function detailHtml(c) {
    return row('Nama',    c.name  ? esc(c.name)  : '', !c.name)
         + row('Phone',   '<span class="mono cs-mask">' + esc(c.phone) + '</span>')
         + row('Catatan', c.notes ? esc(c.notes) : '', !c.notes);
  }

  function itemHtml(c) {
    return '<div class="cs-item" data-id="' + esc(c.id) + '" data-user="' + esc(c.username) + '">'
      + '<div class="cs-item-head">'
      +   '<div class="cs-item-user mono">' + esc(c.username) + '</div>'
      +   '<div class="cs-item-actions">'
      +     '<button type="button" class="btn btn-sm" data-act="edit">Edit</button>'
      +     '<button type="button" class="btn btn-sm btn-danger" data-act="del">Hapus</button>'
      +   '</div>'
      + '</div>'
      + '<dl class="cs-rows">' + detailHtml(c) + '</dl>'
      + '<form class="cs-edit" hidden>'
      +   '<div><label>Nama</label><input class="input" name="name" maxlength="100" value="' + esc(c.name) + '" placeholder="Nama pelanggan"></div>'
      +   '<div><label>Nomor baru <span class="t-mute">(kosongkan kalau tidak diganti)</span></label>'
      +     '<input class="input" name="phone" inputmode="numeric" placeholder="' + esc(c.phone) + '"></div>'
      +   '<div><label>Catatan</label><input class="input" name="notes" maxlength="255" value="' + esc(c.notes) + '" placeholder="catatan internal"></div>'
      +   '<div class="cs-edit-foot">'
      +     '<span class="cs-err"></span>'
      +     '<button type="button" class="btn btn-sm" data-act="cancel">Batal</button>'
      +     '<button type="submit" class="btn btn-sm btn-primary">Simpan</button>'
      +   '</div>'
      + '</form>'
      + '<div class="cs-item-msg"></div>'
      + '</div>';
  }

  function render(data) {
    var list = data.results || [];
    lastQuery = data.query || '';

    if (data.too_short) {
      sub.textContent = 'kata kunci terlalu pendek';
      return note('Ketik minimal 2 karakter.');
    }

    countLabel(list.length);
    if (!list.length) return note('Tidak ada kontak yang cocok.');

    body.innerHTML = list.map(itemHtml).join('');
  }

  function post(url, method, fields) {
    var payload = new URLSearchParams();
    payload.set('_method', method);
    Object.keys(fields || {}).forEach(function (k) { payload.set(k, fields[k]); });

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-TOKEN': TOKEN,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: payload.toString(),
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) {
          var first = j.errors ? j.errors[Object.keys(j.errors)[0]][0] : null;
          throw new Error(first || j.message || ('Gagal (HTTP ' + r.status + ')'));
        }
        return j;
      });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = input.value.trim();
    if (!q) { input.focus(); return; }

    var seq = ++reqSeq;
    lastQuery = q;
    open();
    sub.textContent = 'kata kunci: ' + q;
    body.innerHTML = '<div class="cs-loading"><span class="spin-ring"></span> Mencari</div>';

    fetch(URL_SEARCH + '?q=' + encodeURIComponent(q), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) { if (seq === reqSeq) render(data); })
      .catch(function () {
        if (seq !== reqSeq) return;
        sub.textContent = 'gagal';
        note('Gagal mengambil data. Coba lagi.');
      });
  });

  body.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;

    var item = btn.closest('.cs-item'),
        act  = btn.dataset.act,
        edit = item.querySelector('.cs-edit');

    if (act === 'edit' || act === 'cancel') {
      var opening = act === 'edit';
      edit.hidden = !opening;
      item.querySelector('.cs-rows').hidden = opening;
      item.querySelector('.cs-err').textContent = '';
      if (opening) edit.querySelector('input[name="name"]').focus();
      return;
    }

    if (act === 'del') {
      var user = item.dataset.user;
      window.zeroConfirm({
        title: 'Hapus Kontak WhatsApp',
        message: 'Hapus kontak WA untuk ' + user + '? Nomornya ikut terhapus dan reminder otomatis berhenti untuk pelanggan ini.',
        action: 'Hapus',
        variant: 'danger',
      }).then(function (ok) {
        if (!ok) return;
        item.classList.add('cs-busy');
        item.querySelector('.cs-item-msg').textContent = '';
        post(URL_DESTROY.replace('__ID__', item.dataset.id), 'DELETE', {})
          .then(function () { item.remove(); recount(); })
          .catch(function (err) {
            item.classList.remove('cs-busy');
            item.querySelector('.cs-item-msg').textContent = err.message;
          });
      });
    }
  });

  body.addEventListener('submit', function (e) {
    var edit = e.target.closest('.cs-edit');
    if (!edit) return;
    e.preventDefault();

    var item = edit.closest('.cs-item'),
        err  = edit.querySelector('.cs-err');

    err.textContent = '';
    item.querySelector('.cs-item-msg').textContent = '';
    item.classList.add('cs-busy');

    post(URL_UPDATE.replace('__ID__', item.dataset.id), 'PATCH', {
      name:  edit.querySelector('input[name="name"]').value,
      phone: edit.querySelector('input[name="phone"]').value.trim(),
      notes: edit.querySelector('input[name="notes"]').value,
    })
      .then(function (j) {
        var c = j.contact;
        item.classList.remove('cs-busy');
        item.querySelector('.cs-rows').innerHTML = detailHtml(c);
        item.querySelector('.cs-rows').hidden = false;
        edit.hidden = true;
        edit.querySelector('input[name="phone"]').value = '';
        edit.querySelector('input[name="phone"]').placeholder = c.phone;
      })
      .catch(function (e2) {
        item.classList.remove('cs-busy');
        err.textContent = e2.message;
      });
  });

  closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) close();
  });
})();
</script>

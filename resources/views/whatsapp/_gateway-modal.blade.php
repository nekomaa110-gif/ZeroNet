
<div class="mdl-backdrop" id="wgBackdrop" role="dialog" aria-modal="true" aria-labelledby="wgTitle" hidden>
  <div class="mdl">

    <div class="mdl-head">
      <div class="mdl-head-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
      </div>
      <div class="mdl-title-wrap">
        <h3 class="mdl-title" id="wgTitle">WhatsApp Gateway</h3>
        <p class="mdl-sub" id="wgSub">Memuat status</p>
      </div>
      <span class="badge" id="wgBadge">…</span>
      <button type="button" class="mdl-close" id="wgClose" aria-label="Tutup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="mdl-body" id="wgBody">
      <div class="wg-loading"><span class="spin-ring"></span> Menghubungi gateway</div>
    </div>

    <div class="mdl-foot">
      <span class="wg-foot-note" id="wgNote"></span>
      <button type="button" class="btn btn-sm" id="wgReconnect">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Reconnect
      </button>
      <button type="button" class="btn btn-sm btn-danger" id="wgReset">Logout &amp; QR baru</button>
    </div>

  </div>
</div>


<script>
(function () {
  'use strict';

  var URLS = {
    state:     @json(route('whatsapp.gateway.state')),
    reconnect: @json(route('whatsapp.gateway.reconnect')),
    reset:     @json(route('whatsapp.gateway.reset')),
  };

  var backdrop = document.getElementById('wgBackdrop');
  if (!backdrop) return;
  var elBody  = document.getElementById('wgBody'),
      elBadge = document.getElementById('wgBadge'),
      elSub   = document.getElementById('wgSub'),
      elNote  = document.getElementById('wgNote'),
      btnRe   = document.getElementById('wgReconnect'),
      btnRs   = document.getElementById('wgReset');

  var timer = null, busy = false, lastQr = null, lastStatus = null;

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function prettyNumber(jid) {
    if (!jid) return null;
    var d = String(jid).split(/[:@]/)[0].replace(/[^0-9]/g, '');
    if (!d) return null;
    return '+' + d.replace(/^(\d{2})(\d{3})(\d{4})(\d+)$/, '$1 $2 $3 $4');
  }

  function dur(sec) {
    sec = Math.max(0, Math.round(sec || 0));
    var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600),
        m = Math.floor(sec % 3600 / 60), s = sec % 60;
    if (d) return d + ' hari ' + h + ' jam';
    if (h) return h + ' jam ' + m + ' menit';
    if (m) return m + ' menit ' + s + ' detik';
    return s + ' detik';
  }

  function fmtTime(ms) {
    if (!ms) return '';
    var dt = new Date(ms);
    return dt.toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  var TONE = { open: 'ok', qr: 'warn', connecting: 'warn', disconnected: 'err', unreachable: 'err' };
  var LABEL = {
    open: 'Connected', qr: 'Perlu scan', connecting: 'Menyambung',
    disconnected: 'Terputus', unreachable: 'Gateway mati'
  };

  function row(label, value, mono) {
    return '<div class="wg-row"><dt>' + esc(label) + '</dt>' +
           '<dd' + (mono ? ' class="mono"' : '') + '>' + esc(value) + '</dd></div>';
  }

  function render(j) {
    var st = j.status || 'unreachable';
    elBadge.className = 'badge ' + (TONE[st] || 'err');
    elBadge.textContent = LABEL[st] || st;

    var num = prettyNumber(j.user);
    elSub.textContent = st === 'open' && num ? 'Terhubung sebagai ' + num
                      : st === 'qr'          ? 'Menunggu scan dari HP'
                      : st === 'unreachable' ? 'Service gateway tidak merespons'
                      : 'Menyambungkan ulang otomatis…';

    elNote.textContent = st === 'open'
      ? 'Auto-refresh tiap 2,5 detik'
      : (j.seconds_in_state != null ? dur(j.seconds_in_state) + ' di status ini' : '');
    btnRs.disabled = busy;
    btnRe.disabled = busy;

    if (st === 'qr' && j.qr) {
      if (lastQr === j.qr && lastStatus === st) return;
      lastQr = j.qr; lastStatus = st;
      elBody.innerHTML =
        '<div class="wg-qr-wrap">' +
          '<div class="wg-qr-box"><img alt="QR WhatsApp" src="' + esc(j.qr) + '"></div>' +
          '<ol class="wg-steps">' +
            '<li>Buka <b>WhatsApp</b> di HP</li>' +
            '<li>Menu <b>⋮</b> → <b>Perangkat tertaut</b></li>' +
            '<li>Ketuk <b>Tautkan perangkat</b>, arahkan ke QR di atas</li>' +
          '</ol>' +
        '</div>';
      return;
    }

    lastQr = null;
    if (lastStatus === st && st !== 'open') return;
    lastStatus = st;

    if (st === 'open') {
      elBody.innerHTML =
        '<div class="wg-hero">' +
          '<div class="wg-hero-ring tone-ok">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +
          '</div>' +
          '<div class="wg-hero-t">Gateway aktif</div>' +
          '<div class="wg-hero-s">Siap mengirim pesan</div>' +
        '</div>' +
        '<div class="wg-info">' +
          row('Nomor terhubung', prettyNumber(j.user) || '', true) +
          row('Terhubung sejak', fmtTime(j.last_connected_at)) +
          row('Lama tersambung', dur(j.seconds_in_state)) +
          row('Uptime service', dur(j.uptime)) +
          (j.last_disconnect_code
            ? row('Putus terakhir', j.last_disconnect_code + ' · ' + (j.last_disconnect_reason || ''))
            : '') +
        '</div>';
      return;
    }

    var isDead = st === 'unreachable';
    elBody.innerHTML =
      '<div class="wg-hero">' +
        '<div class="wg-hero-ring tone-' + (isDead ? 'err' : 'warn') + '">' +
          (isDead
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            : '<span class="spin-ring lg"></span>') +
        '</div>' +
        '<div class="wg-hero-t">' + (isDead ? 'Gateway tidak merespons' : 'Menyambung ke WhatsApp') + '</div>' +
        '<div class="wg-hero-s">' + (isDead
          ? 'Service di port 3001 kemungkinan mati. Cek PM2 di server.'
          : 'Watchdog akan restart sendiri kalau macet lebih dari 90 detik.') + '</div>' +
      '</div>' +
      (isDead || !(j.last_disconnect_code || j.failed_since_open) ? '' :
      '<div class="wg-info">' +
        (j.last_disconnect_code ? row('Kode putus terakhir', j.last_disconnect_code + ' · ' + (j.last_disconnect_reason || '')) : '') +
        (j.failed_since_open ? row('Gagal beruntun', j.failed_since_open + '×') : '') +
      '</div>');
  }

  async function poll() {
    try {
      var res = await fetch(URLS.state, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      render(res.ok ? await res.json() : { status: 'unreachable' });
    } catch (e) {
      render({ status: 'unreachable' });
    }
  }

  async function control(url, label) {
    busy = true;
    btnRe.disabled = btnRs.disabled = true;
    elNote.textContent = label + '…';
    try {
      await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      });
    } catch (e) {}
    busy = false;
    lastStatus = null; lastQr = null;
    setTimeout(poll, 700);
  }

  function open() {
    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    lastStatus = null; lastQr = null;
    poll();
    if (timer) clearInterval(timer);
    timer = setInterval(poll, 2500);
  }

  function close() {
    backdrop.classList.remove('is-open');
    if (timer) { clearInterval(timer); timer = null; }
    setTimeout(function () { backdrop.hidden = true; }, 180);
  }

  document.getElementById('wgClose').addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !backdrop.hidden) close();
  });

  btnRe.addEventListener('click', function () {
    control(URLS.reconnect, 'Reconnect');
  });

  btnRs.addEventListener('click', function () {
    window.zeroConfirm({
      title: 'Putus sesi WhatsApp',
      message: 'Sesi sekarang akan diputus dan gateway generate QR baru. Kamu harus scan ulang dari HP, dan reminder otomatis berhenti sampai QR di-scan.',
      action: 'Putus & buat QR',
      variant: 'danger',
    }).then(function (ok) { if (ok) control(URLS.reset, 'Reset sesi'); });
  });

  document.querySelectorAll('[data-wa-gateway]').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); open(); });
  });
})();
</script>

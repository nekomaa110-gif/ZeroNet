<section class="card" id="bcProg"
     data-status-url="{{ route('whatsapp.broadcast.status', ['broadcast' => '__ID__']) }}"
     data-retry-url="{{ route('whatsapp.broadcast.retry', ['broadcast' => '__ID__']) }}"
     data-cancel-url="{{ route('whatsapp.broadcast.cancel', ['broadcast' => '__ID__']) }}"
     data-initial="{{ json_encode($broadcast) }}" aria-live="polite">

  <div class="card-head bc-prog-head">
    <div class="grow">
      <h3 class="title-inline">
        <span id="bcProgIcon" class="spin-ring"></span>
        <span id="bcProgTitle">Broadcast berjalan</span>
      </h3>
      <p id="bcProgSub">Menyiapkan</p>
    </div>
    <div class="ch-actions">
      <button type="button" class="btn btn-sm" id="bcProgRetry" hidden>Ulangi yang gagal</button>
      <button type="button" class="btn btn-sm t-err" id="bcProgCancel" hidden>Hentikan</button>
    </div>
  </div>

  <div class="card-pad form-stack">
    <div class="label-row">
      <b class="bc-count tnum" id="bcProgCount">0 dari 0</b>
      <span class="hint" id="bcProgEta"></span>
    </div>

    <div class="progress"><i id="bcProgBar" style="width:0"></i></div>

    <div class="chip-row">
      <span class="bc-chip bc-chip-ok">Terkirim <b id="bcCntSent">0</b></span>
      <span class="bc-chip bc-chip-wait">Menunggu <b id="bcCntWait">0</b></span>
      <span class="bc-chip bc-chip-err">Gagal <b id="bcCntFail">0</b></span>
      <span class="bc-chip" id="bcChipCancel" hidden>Dibatalkan <b id="bcCntCancel">0</b></span>
    </div>

    <div id="bcProgWarn" class="note-inline" hidden>
      Tidak ada pengiriman baru selama 10 menit terakhir. Worker antrean mungkin berhenti.
      Cek PM2, lalu klik <b>Ulangi yang gagal</b> untuk melanjutkan sisanya.
    </div>

    <details id="bcProgDetail">
      <summary class="summary-link">Lihat daftar penerima</summary>
      <div class="bc-rows-wrap">
        <table class="tbl bc-rows">
          <tbody id="bcProgRows"></tbody>
        </table>
      </div>
    </details>
  </div>
</section>

<script>
(function () {
  var box = document.getElementById('bcProg');
  if (!box) return;

  var data  = JSON.parse(box.dataset.initial || 'null');
  if (!data) return;

  var url = function (tpl) { return box.dataset[tpl].replace('__ID__', data.id); },
      csrf = document.querySelector('meta[name="csrf-token"]').content,
      bar    = document.getElementById('bcProgBar'),
      icon   = document.getElementById('bcProgIcon'),
      title  = document.getElementById('bcProgTitle'),
      sub    = document.getElementById('bcProgSub'),
      count  = document.getElementById('bcProgCount'),
      eta    = document.getElementById('bcProgEta'),
      warn   = document.getElementById('bcProgWarn'),
      rows   = document.getElementById('bcProgRows'),
      btnRetry  = document.getElementById('bcProgRetry'),
      btnCancel = document.getElementById('bcProgCancel'),
      timer;

  var LABEL = {
    sent:      'Terkirim',
    failed:    'Gagal',
    sending:   'Mengirim…',
    pending:   'Menunggu',
    cancelled: 'Dibatalkan',
  };

  var ICON_OK  = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" class="bc-ico"><path d="M20 6 9 17l-5-5"/></svg>';
  var ICON_ERR = '<svg viewBox="0 0 24 24" fill="none" stroke="var(--err)" stroke-width="2.4" stroke-linecap="round" class="bc-ico"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function paint(j) {
    data = j;

    var c    = j.counts,
        sisa = c.pending + c.sending;

    bar.style.width = j.percent + '%';
    count.textContent = j.done + ' dari ' + j.total + ' selesai · ' + j.percent + '%';

    document.getElementById('bcCntSent').textContent   = c.sent;
    document.getElementById('bcCntWait').textContent   = sisa;
    document.getElementById('bcCntFail').textContent   = c.failed;
    document.getElementById('bcCntCancel').textContent = c.cancelled;
    document.getElementById('bcChipCancel').hidden = !c.cancelled;

    if (j.running) {
      icon.className = 'spin-ring';
      icon.innerHTML = '';
      title.textContent = 'Broadcast berjalan';
      eta.textContent = sisa ? 'Sisa ' + sisa + ' pesan · ±' + Math.max(1, Math.ceil(sisa * 7 / 60)) + ' menit lagi' : '';
    } else {
      icon.className = '';
      icon.innerHTML = c.failed ? ICON_ERR : ICON_OK;
      title.textContent = j.status === 'cancelled' ? 'Broadcast dihentikan' : 'Broadcast selesai';
      eta.textContent = j.finished_at ? 'Selesai ' + j.finished_at : '';
    }

    sub.textContent = 'Target: ' + j.target + ' · mulai ' + j.started_at;
    warn.hidden = !j.stalled;
    btnCancel.hidden = !j.running;
    btnRetry.hidden  = !(c.failed || j.stalled);

    rows.innerHTML = j.recipients.map(function (r) {
      var kanan = r.status === 'sent' && r.sent_at ? r.sent_at
                : (r.error ? esc(r.error) : '');
      return '<tr>'
        + '<td class="bc-who"><b>' + esc(r.name) + '</b><div class="hint mono">' + esc(r.username) + '</div></td>'
        + '<td class="mono t-mute">' + esc(r.phone) + '</td>'
        + '<td class="bc-st bc-st-' + r.status + '">' + LABEL[r.status] + '</td>'
        + '<td class="hint">' + kanan + '</td>'
        + '</tr>';
    }).join('');

    if (!j.running && timer) { clearInterval(timer); timer = null; }
  }

  function poll() {
    fetch(url('statusUrl'), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(paint)
      .catch(function () {});
  }

  function post(which, btn) {
    btn.disabled = true;
    fetch(url(which), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.id) paint(j);
        if (j.ok === false && j.message) window.vgToast(j.message, 'err');
        if (j.ok && !timer) timer = setInterval(poll, 3000);
      })
      .finally(function () { btn.disabled = false; });
  }

  btnRetry.addEventListener('click', function () { post('retryUrl', btnRetry); });
  btnCancel.addEventListener('click', function () {
    window.zeroConfirm({
      title: 'Hentikan broadcast?',
      message: 'Pesan yang belum terkirim akan dibatalkan.',
      action: 'Hentikan',
      cancel: 'Lanjutkan kirim',
      variant: 'warning',
    }).then(function (ok) { if (ok) post('cancelUrl', btnCancel); });
  });

  paint(data);
  if (data.running) timer = setInterval(poll, 3000);

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (timer) { clearInterval(timer); timer = null; }
    } else if (data.running) {
      poll();
      if (!timer) timer = setInterval(poll, 3000);
    }
  });
})();
</script>

@include('vouchers._toast')

@extends('layouts.app')

@section('title', 'WhatsApp Gateway')
@section('page-title', 'WhatsApp Gateway')

@php
    $st = $status['status'] ?? 'unknown';
    $statusTone = match($st) {
        'open'             => 'ok',
        'qr', 'connecting' => 'warn',
        default            => 'err',
    };
    $phoneNumber = $status['number'] ?? $status['phone'] ?? null;
    $weeklyCount = $status['weekly_count'] ?? null;
    $statusLabel = match($st) {
        'open'       => 'Tersambung',
        'qr'         => 'Menunggu scan QR',
        'connecting' => 'Menyambungkan',
        default      => ucfirst($st),
    };
@endphp

@section('content')

  <header class="page-head">
    <div>
      <h2>WhatsApp Gateway</h2>
      <p>Kirim pesan WhatsApp dan kelola kontak pelanggan untuk reminder otomatis.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('whatsapp.cloud.index') }}" class="btn btn-sm" title="Sambungkan nomor bisnis ke jalur resmi Meta">Cloud API</a>
      <form id="csForm" class="cs-form" autocomplete="off" role="search">
        <div class="input-group">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" id="csInput" class="input" placeholder="Cari username, nama, atau nomor" aria-label="Cari kontak pelanggan">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" id="csSubmit">Cari</button>
      </form>
    </div>
  </header>

  <div class="stack">

    <section class="status-strip tone-{{ $statusTone }}" aria-label="Status gateway">
      <span class="led {{ $statusTone }} {{ $statusTone === 'ok' ? 'pulse' : '' }}" aria-hidden="true"></span>
      <div class="status-strip-text">
        <b>{{ $statusLabel }}</b>
        <span>
          @if($phoneNumber)Terhubung sebagai <span class="mono">{{ $phoneNumber }}</span>@else WhatsApp Gateway @endif
          @if($weeklyCount) / {{ $weeklyCount }} pesan minggu ini @endif
        </span>
      </div>
      <button type="button" class="btn btn-sm" data-wa-gateway title="Buka panel gateway">
        Panel<span class="hide-sm">&nbsp;gateway</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </section>

    @if(session('ok'))
      <x-admin.alert type="success" :message="session('ok')"/>
    @endif

    @if(session('wa_track'))
      <div class="card card-pad wa-prog" id="waProg" data-track="{{ session('wa_track') }}"
           data-url="{{ route('whatsapp.send-status', ['track' => '__ID__']) }}" role="status" aria-live="polite">
        <div class="wa-prog-head">
          <div id="waProgIcon" class="status-icon tone-brand">
            <span class="spin-ring"></span>
          </div>
          <div class="wa-prog-text">
            <b id="waProgTitle">Mengirim pesan</b>
            <div id="waProgSub">Menunggu worker mengambil job</div>
          </div>
          <span class="badge" id="waProgBadge">Antre</span>
        </div>

        <div class="progress"><i id="waProgBar" style="width:15%"></i></div>

        <div id="waProgSteps" class="wa-prog-steps">
          <span data-step="queued">Masuk antrean</span><span>›</span>
          <span data-step="processing">Diproses worker</span><span>›</span>
          <span data-step="sending">Dikirim ke WhatsApp</span><span>›</span>
          <span data-step="sent">Terkirim</span>
        </div>
      </div>


      <script>
      (function(){
        var box = document.getElementById('waProg');
        if (!box) return;
        var url   = box.dataset.url.replace('__ID__', encodeURIComponent(box.dataset.track)),
            bar   = document.getElementById('waProgBar'),
            icon  = document.getElementById('waProgIcon'),
            title = document.getElementById('waProgTitle'),
            sub   = document.getElementById('waProgSub'),
            badge = document.getElementById('waProgBadge'),
            steps = document.getElementById('waProgSteps'),
            ORDER = ['queued','processing','sending','sent'],
            tries = 0, timer;

        var ICON_OK   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        var ICON_ERR  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

        function paintSteps(status){
          var i = ORDER.indexOf(status);
          steps.querySelectorAll('[data-step]').forEach(function(el, n){
            el.classList.toggle('done', i > -1 && n < i);
            el.classList.toggle('on',   n === i);
          });
        }

        function tone(el, v){ el.className = 'status-icon tone-' + v; }

        function finish(j){
          clearInterval(timer);
          bar.style.width = '100%';
          if (j.status === 'sent') {
            var who = j.name || j.number;
            bar.parentNode.className = 'progress ok';
            icon.innerHTML = ICON_OK; tone(icon, 'ok');
            title.textContent = 'Pesan terkirim ke ' + who;
            sub.textContent = j.message_id ? 'ID pesan: ' + j.message_id : 'Berhasil dikirim';
            badge.className = 'badge ok'; badge.textContent = 'Terkirim';
            paintSteps('sent');
            steps.querySelectorAll('[data-step]').forEach(function(el){ el.classList.add('done'); });
          } else {
            bar.parentNode.className = 'progress err';
            icon.innerHTML = ICON_ERR; tone(icon, 'err');
            title.textContent = 'Pesan gagal terkirim';
            sub.textContent = j.error || 'Penyebab tidak diketahui.';
            badge.className = 'badge err'; badge.textContent = 'Gagal';
          }
        }

        async function poll(){
          if (++tries > 150) {
            clearInterval(timer);
            sub.textContent = 'Status tidak lagi terpantau. Cek Log Aktivitas.';
            return;
          }
          try {
            var j = await (await fetch(url, {credentials:'same-origin',headers:{'Accept':'application/json'}})).json();
            if (j.status === 'unknown') { clearInterval(timer); return; }

            bar.style.width = (j.percent || 15) + '%';
            paintSteps(j.status);

            if (j.done) return finish(j);

            badge.className = 'badge warn';
            badge.textContent = j.status === 'queued' ? 'Antre' : 'Proses';
            title.textContent = 'Mengirim pesan';
            sub.textContent = j.error
              ? j.error + (j.attempt > 1 ? ' (percobaan ' + j.attempt + ')' : '')
              : (j.label || '') + (j.name ? ' (tujuan ' + j.name + ')' : '');
          } catch (e) {}
        }

        poll();
        timer = setInterval(poll, 900);
      })();
      </script>
    @endif

    @if($errors->any())
      <div class="alert tone-err" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <ul class="alert-text plain-list">
          @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div class="wa-cols">
      <div class="card wa-fill">
        <div class="card-head"><h3>Kirim manual</h3></div>
        <form method="post" action="{{ route('whatsapp.send') }}" class="card-pad wa-fill form-stack">
          @csrf
          <div class="field">
            <label for="wa-contact">Tujuan <span class="t-mute">({{ $contacts->count() }} kontak)</span></label>
            <select id="wa-contact" name="contact_id" required class="select">
              <option value="">Pilih kontak</option>
              @foreach($contacts as $c)
                <option value="{{ $c->id }}">{{ $c->username }}{{ $c->name && $c->name !== $c->username ? ' ('.$c->name.')' : '' }}</option>
              @endforeach
            </select>
          </div>
          <div class="field grow">
            <label for="wa-message">Pesan</label>
            <textarea id="wa-message" name="message" rows="4" required placeholder="Isi pesan" class="textarea grow"></textarea>
          </div>
          <div>
            <button type="submit" class="btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Kirim
            </button>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="card-head"><h3>Tambah kontak pelanggan</h3></div>
        <form method="post" action="{{ route('whatsapp.contacts.store') }}" class="card-pad form-stack">
          @csrf
          <div class="field">
            <label for="wa-uname">Username <span class="t-mute">({{ $availableUsernames->count() }} tersedia)</span></label>
            <select id="wa-uname" name="username" required class="select">
              <option value="">Pilih username</option>
              @foreach($availableUsernames as $uname)
                <option value="{{ $uname }}">{{ $uname }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="wa-phone">Nomor WhatsApp</label>
            <input id="wa-phone" name="phone" required placeholder="08xxxxxxxxxx" class="input mono" inputmode="tel">
          </div>
          <div class="grid-2">
            <div class="field">
              <label for="wa-name">Nama <span class="t-mute">(opsional)</span></label>
              <input id="wa-name" name="name" placeholder="Nama pelanggan" class="input">
            </div>
            <div class="field">
              <label for="wa-notes">Catatan <span class="t-mute">(opsional)</span></label>
              <input id="wa-notes" name="notes" placeholder="Catatan internal" class="input">
            </div>
          </div>
          <div>
            <button type="submit" class="btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
              Simpan kontak
            </button>
          </div>
        </form>
      </div>
    </div>

    @if(!empty($broadcast))
      @include('whatsapp._broadcast-progress')
    @endif

    @php
      $tplPortal   = \App\Services\MessageTemplateService::body('broadcast_portal');
      $tplReminder = \App\Services\MessageTemplateService::body('broadcast_reminder');
      $tplGangguan = \App\Services\MessageTemplateService::body('broadcast_gangguan');

      $tplGlobals = \App\Services\MessageTemplateService::globalVars();
      foreach ([&$tplPortal, &$tplReminder, &$tplGangguan] as &$tplBody) {
          foreach ($tplGlobals as $tplName => $tplValue) {
              $tplBody = str_replace('{' . $tplName . '}', $tplValue, $tplBody);
          }
      }
      unset($tplBody);
      $bcCounts   = $bcCounts ?? ['all' => 0, 'active' => 0, 'expiring' => 0];
      $bcEstimate = max(1, (int) ceil($bcCounts['all'] * 7 / 60));
      $previewContact = $contacts->whereNotNull('phone')->where('phone', '!=', '')->first();
      $bcSedangJalan = !empty($broadcast) && $broadcast['running'];
    @endphp
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Broadcast pesan</h3>
          <p>Kirim pesan yang sama ke banyak pelanggan sekaligus.</p>
        </div>
      </div>
      <form method="post" action="{{ route('whatsapp.broadcast') }}" class="card-pad bc-grid"
            data-confirm="Yakin kirim broadcast ke pelanggan terpilih? Proses akan jalan di background, ada delay 4-10 detik antar pesan."
            data-confirm-title="Broadcast WhatsApp"
            data-confirm-action="Kirim Broadcast"
            data-confirm-variant="warning">
        @csrf

        <div class="field">
          <label for="bc-target">Target</label>
          <select name="target" required class="select" id="bc-target">
            <option value="all"      data-count="{{ $bcCounts['all'] }}">Semua kontak ({{ $bcCounts['all'] }} pelanggan)</option>
            <option value="active"   data-count="{{ $bcCounts['active'] }}">Hanya akun aktif ({{ $bcCounts['active'] }} pelanggan)</option>
            <option value="expiring" data-count="{{ $bcCounts['expiring'] }}">Akan expired dalam 7 hari ({{ $bcCounts['expiring'] }} pelanggan)</option>
          </select>
          <p id="bc-target-info" class="hint"></p>
        </div>

        <div class="field">
          <span class="field-label" id="bc-tpl-label">Template cepat</span>
          <div class="chip-row" role="group" aria-labelledby="bc-tpl-label">
            <button type="button" class="chip bc-tpl-chip" data-tpl="portal">Pengumuman portal baru</button>
            <button type="button" class="chip bc-tpl-chip" data-tpl="reminder">Reminder perpanjang</button>
            <button type="button" class="chip bc-tpl-chip" data-tpl="gangguan">Info gangguan</button>
            <button type="button" class="chip bc-tpl-chip is-danger push-end" data-tpl="clear">Kosongkan</button>
          </div>
        </div>

        <div class="field">
          <label for="bc-message" class="label-row">
            <span>Pesan <span class="t-mute">(pakai <code class="code-chip">{name}</code> atau <code class="code-chip">{username}</code>)</span></span>
            <span id="bc-charcount" class="mono t-mute">0/1500</span>
          </label>
          <textarea name="message" id="bc-message" rows="9" required class="textarea"
                    placeholder="Tulis pesan, atau klik template di atas untuk mengisi otomatis"></textarea>
        </div>

        <div class="bc-preview-wrap field">
          <span class="field-label">
            Pratinjau
            @if ($previewContact)
              <span class="t-mute">(dengan {name} = {{ $previewContact->name ?: $previewContact->username }}, {username} = {{ $previewContact->username }})</span>
            @endif
          </span>
          <div id="bc-preview" class="wa-chat">
            <div id="bc-preview-bubble" class="wa-bubble"><span class="wa-bubble-empty">Pesan akan tampil di sini</span></div>
          </div>
        </div>

        <div class="bc-span note-inline" id="bc-estimate">
          <b>Estimasi:</b> sekitar {{ $bcEstimate }} menit untuk {{ $bcCounts['all'] }} kontak (delay 4-10 detik antar pesan untuk hindari spam-flag WA).
        </div>

        <div class="bc-span">
          <button type="submit" class="btn btn-primary"
                  @disabled($bcSedangJalan)
                  title="{{ $bcSedangJalan ? 'Tunggu broadcast yang sedang berjalan selesai' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
            <span id="bc-submit-label">{{ $bcSedangJalan ? 'Broadcast sedang berjalan' : 'Mulai broadcast' }}</span>
          </button>
        </div>
      </form>
    </section>

    <script>
      (function() {
        const templates = {
          portal: @json($tplPortal),
          reminder: @json($tplReminder),
          gangguan: @json($tplGangguan),
          clear: '',
        };
        const ta = document.getElementById('bc-message');
        const counter = document.getElementById('bc-charcount');
        const previewBubble = document.getElementById('bc-preview-bubble');
        const sampleName = @json($previewContact?->name ?: $previewContact?->username ?? 'Pelanggan');
        const sampleUsername = @json($previewContact?->username ?? 'username');

        function updatePreview() {
          const text = ta.value;
          counter.textContent = text.length + '/1500';
          counter.classList.toggle('t-err', text.length > 1400);

          if (!text.trim()) {
            previewBubble.innerHTML = '<span class="wa-bubble-empty">Pesan akan tampil di sini</span>';
            return;
          }
          let preview = text
            .replaceAll('{name}', sampleName)
            .replaceAll('{username}', sampleUsername);
          preview = preview.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
          preview = preview.replace(/\*([^*\n]+)\*/g, '<b>$1</b>');
          preview = preview.replace(/_([^_\n]+)_/g, '<i>$1</i>');
          previewBubble.innerHTML = preview;
        }

        document.querySelectorAll('.bc-tpl-chip').forEach(btn => {
          btn.addEventListener('click', () => {
            ta.value = templates[btn.dataset.tpl] || '';
            updatePreview();
            ta.focus();
          });
        });
        ta.addEventListener('input', updatePreview);
        updatePreview();

        const target  = document.getElementById('bc-target');
        const info    = document.getElementById('bc-target-info');
        const estimate= document.getElementById('bc-estimate');
        const submit  = document.querySelector('.bc-grid button[type="submit"]');
        const label   = document.getElementById('bc-submit-label');
        const sedangJalan = @json($bcSedangJalan ?? false);

        function updateTarget() {
          const opt = target.selectedOptions[0];
          const n   = parseInt(opt.dataset.count || '0', 10);
          const menit = Math.max(1, Math.ceil(n * 7 / 60));

          if (n === 0) {
            info.innerHTML = '<span class="t-err">Tidak ada pelanggan yang cocok dengan filter ini.</span>';
            estimate.innerHTML = 'Tidak ada penerima. Pilih target lain.';
          } else {
            info.innerHTML = 'Pesan akan dikirim ke <b>' + n + ' pelanggan</b>.';
            estimate.innerHTML = '<b>Estimasi:</b> sekitar ' + menit + ' menit untuk ' + n +
              ' kontak (delay 4-10 detik antar pesan untuk hindari spam-flag WA).';
          }

          if (!sedangJalan) {
            submit.disabled = n === 0;
            label.textContent = n === 0 ? 'Tidak ada penerima' : 'Mulai broadcast ke ' + n + ' pelanggan';
          }
        }

        target.addEventListener('change', updateTarget);
        updateTarget();
      })();
    </script>

  </div>
@endsection

@push('overlays')
  @include('whatsapp._gateway-modal')
  @include('whatsapp._contact-search-modal')
@endpush

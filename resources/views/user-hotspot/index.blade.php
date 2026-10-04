@extends('layouts.app')

@section('title', 'User Hotspot')
@section('page-title', 'User Hotspot')

@section('content')

  <header class="page-head">
    <div>
      <h2>User Hotspot</h2>
      <p>Kelola akun user hotspot.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('user-hotspot.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
         class="btn" data-page-transition="none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export
      </a>
      <a href="{{ route('user-hotspot.create') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah User
      </a>
    </div>
  </header>

  @php
    $segments = [
      ['k' => '',         'l' => 'Semua',    'n' => $stats['total'],    'tone' => ''],
      ['k' => 'aktif',    'l' => 'Aktif',    'n' => $stats['active'],   'tone' => ''],
      ['k' => 'expired',  'l' => 'Expired',  'n' => $stats['expired'],  'tone' => $stats['expired'] > 0 ? 'is-err' : ''],
      ['k' => 'nonaktif', 'l' => 'Nonaktif', 'n' => $stats['disabled'], 'tone' => $stats['disabled'] > 0 ? 'is-warn' : ''],
    ];
  @endphp

  <div class="card">
    <form method="GET" action="{{ route('user-hotspot.index') }}" data-live-target="#radius-users-results">
      <div class="tabs card-tabs" role="tablist" aria-label="Filter status">
        @foreach ($segments as $seg)
          @php $on = (string)$status === (string)$seg['k']; @endphp
          <button type="button" role="tab" data-status-pick="{{ $seg['k'] }}" class="{{ $on ? 'active' : '' }}" aria-selected="{{ $on ? 'true' : 'false' }}">
            {{ $seg['l'] }} <span class="count {{ $seg['tone'] }}">{{ number_format($seg['n']) }}</span>
          </button>
        @endforeach
      </div>

      <div class="toolbar">
        <input type="hidden" name="status" id="status-input" value="{{ $status }}" data-live-submit>
        <input type="hidden" name="sort" id="sort-input" value="{{ $sort }}" data-live-submit>

        <div class="input-group tb-search">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="search" name="search" value="{{ $search }}" placeholder="Cari username atau paket" aria-label="Cari user"
                 data-live-search class="input" />
        </div>

        <select name="group" data-live-submit class="select tb-select" aria-label="Filter paket">
          <option value="">Semua paket</option>
          @foreach ($groups as $g)
            <option value="{{ $g }}" {{ $group === $g ? 'selected' : '' }}>{{ $g }}</option>
          @endforeach
        </select>

        <div class="seg" role="group" aria-label="Urutan">
          <button type="button" data-sort-pick="up" class="{{ $sort === 'up' ? 'active' : '' }}" aria-pressed="{{ $sort === 'up' ? 'true' : 'false' }}" aria-label="Urutkan naik">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
          </button>
          <button type="button" data-sort-pick="down" class="{{ $sort === 'down' ? 'active' : '' }}" aria-pressed="{{ $sort === 'down' ? 'true' : 'false' }}" aria-label="Urutkan turun">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
          </button>
        </div>

        <div class="tb-end"><span class="live">Live</span></div>
      </div>
    </form>

    <div id="radius-users-results" data-live-results>
      @include('user-hotspot._results')
    </div>
  </div>

  <div class="drawer-overlay" id="drawer-ov"></div>
  <aside class="drawer" id="drawer" aria-hidden="true" aria-labelledby="dr-name" role="dialog">
    <div class="drawer-head">
      <div class="avatar" id="dr-avatar" aria-hidden="true">A</div>
      <div class="drawer-title">
        <h3 id="dr-name"></h3>
        <p id="dr-sub"></p>
      </div>
      <button class="icon-btn" id="dr-close" type="button" aria-label="Tutup detail">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <div class="drawer-body">
      <div class="drawer-status" id="dr-status-row"></div>

      <h4 class="dr-section">Detail akun</h4>
      <div class="kvp"><span class="k">Username</span><span class="v mono" id="dr-username"></span></div>
      <div class="kvp"><span class="k">Paket</span><span class="v" id="dr-paket"></span></div>
      <div class="kvp"><span class="k">Expire</span><span class="v mono" id="dr-expire"></span></div>
      <div class="kvp"><span class="k">Sisa waktu</span><span class="v mono" id="dr-remain"></span></div>

      <h4 class="dr-section">Sesi terakhir</h4>
      <div class="kvp"><span class="k">IP klien</span><span class="v mono" id="dr-ip"></span></div>
      <div class="kvp"><span class="k">MAC address</span><span class="v mono" id="dr-mac"></span></div>
      <div class="kvp"><span class="k">Login terakhir</span><span class="v" id="dr-last"></span></div>
      <div class="kvp"><span class="k">RX / TX</span><span class="v mono" id="dr-rxtx"></span></div>

      <div class="dr-inv" id="dr-invoice" hidden>
        <h4 class="dr-section">Invoice terbuka</h4>
        <div class="kvp"><span class="k">Invoice</span><span class="v mono" id="dr-invoice-label"></span></div>
        @if (auth()->user()?->isAdmin())
          <fieldset class="dr-inv-opsi" id="dr-invoice-opsi">
            <legend>Saat diperpanjang, invoice ini</legend>
            <label class="dr-inv-radio"><input type="radio" name="invoice_aksi" value="lunas" form="dr-extend-form"> Sudah dibayar, tandai lunas</label>
            <label class="dr-inv-radio"><input type="radio" name="invoice_aksi" value="batal" form="dr-extend-form"> Batalkan, perpanjang tanpa bayar</label>
          </fieldset>
        @endif
        <p class="dr-inv-note" id="dr-invoice-note"></p>
      </div>

      <h4 class="dr-section">Pengingat WA</h4>
      <p class="muted">Reminder otomatis dikirim 2 hari sebelum expire melalui WhatsApp Gateway.</p>
    </div>

    <div class="drawer-foot">
      <a href="#" id="dr-edit" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
      </a>
      <a href="#" id="dr-print" class="btn" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Voucher
      </a>
      <form method="POST" id="dr-extend-form" action="{{ route('user-hotspot.index') }}"
            class="push-end"
            data-confirm-title="Perpanjang Masa Aktif"
            data-confirm-action="Perpanjang"
            data-confirm-variant="info">
        @csrf
        <input type="hidden" name="days" value="30">
        <input type="hidden" name="invoice_id" id="dr-invoice-id" value="">
        <button type="submit" class="btn btn-primary" id="dr-extend">Perpanjang 30 hari</button>
      </form>
    </div>
  </aside>

  <script>
    (function () {
      const SORT_HINTS = {
        ''         : { up: 'Nama A ke Z',                down: 'Nama Z ke A' },
        'nonaktif' : { up: 'Nama A ke Z',                down: 'Nama Z ke A' },
        'aktif'    : { up: 'Sisa masa aktif terbanyak',  down: 'Paling cepat habis' },
        'expired'  : { up: 'Paling baru expired',        down: 'Paling lama expired' },
      };

      function syncSortHints() {
        const status = document.getElementById('status-input')?.value ?? '';
        const hints  = SORT_HINTS[status] || SORT_HINTS[''];
        document.querySelectorAll('[data-sort-pick]').forEach(b => {
          b.title = hints[b.getAttribute('data-sort-pick')] || '';
        });
      }

      function bindStatusPicks() {
        document.querySelectorAll('[data-status-pick]').forEach(btn => {
          if (btn._bound) return;
          btn._bound = true;
          btn.addEventListener('click', () => {
            document.querySelectorAll('[data-status-pick]').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');

            const form = btn.closest('form');
            const hidden = form?.querySelector('#status-input');
            if (!hidden) return;
            hidden.value = btn.getAttribute('data-status-pick');
            syncSortHints();
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
          });
        });

        document.querySelectorAll('[data-sort-pick]').forEach(btn => {
          if (btn._bound) return;
          btn._bound = true;
          btn.addEventListener('click', () => {
            const dir = btn.getAttribute('data-sort-pick');
            const hidden = document.getElementById('sort-input');
            if (!hidden || hidden.value === dir) return;

            document.querySelectorAll('[data-sort-pick]').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
            btn.classList.add('active');
            btn.setAttribute('aria-pressed', 'true');

            hidden.value = dir;
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
          });
        });
      }
      bindStatusPicks();
      syncSortHints();

      const drawer  = document.getElementById('drawer');
      const overlay = document.getElementById('drawer-ov');
      const closeBtn = document.getElementById('dr-close');

      const editTpl    = '{{ route('user-hotspot.edit', ['radius_user' => '__NAME__']) }}';
      const voucherTpl = '{{ route('user-hotspot.voucher', ['radius_user' => '__NAME__']) }}';
      const extendTpl  = '{{ route('user-hotspot.extend', ['radius_user' => '__NAME__']) }}';

      const EXTEND_DAYS = 30;
      const extendForm  = document.getElementById('dr-extend-form');
      const extendBtn   = document.getElementById('dr-extend');
      const invBox      = document.getElementById('dr-invoice');
      const invOpsi     = document.getElementById('dr-invoice-opsi');
      const invNote     = document.getElementById('dr-invoice-note');
      const invIdInput  = document.getElementById('dr-invoice-id');
      const invRadios   = document.querySelectorAll('input[name="invoice_aksi"]');
      let extendBase    = '';

      function aksiInvoice() {
        const r = document.querySelector('input[name="invoice_aksi"]:checked');
        return r ? r.value : '';
      }

      function setConfirmExtend() {
        const aksi = aksiInvoice();
        const id   = invIdInput.value;
        let tambahan = '';
        if (id && aksi === 'lunas') tambahan = '\n\nInvoice #' + id + ' ditandai lunas.';
        if (id && aksi === 'batal') tambahan = '\n\nInvoice #' + id + ' dibatalkan.';
        extendForm.setAttribute('data-confirm', extendBase + tambahan);
      }

      function isiInvoice(ds) {
        const jumlah = parseInt(ds.invoiceCount || '0', 10);
        invIdInput.value = jumlah === 1 ? (ds.invoiceId || '') : '';
        invRadios.forEach(r => { r.checked = false; });
        invBox.hidden = jumlah === 0;
        document.getElementById('dr-invoice-label').textContent = ds.invoiceLabel || '';
        if (invOpsi) invOpsi.hidden = jumlah !== 1;

        let catatan = '';
        if (jumlah > 1) {
          catatan = 'Ada ' + jumlah + ' invoice terbuka. Rapikan dulu di halaman Tagihan, baru perpanjang.';
        } else if (jumlah === 1 && !invOpsi) {
          catatan = 'Hanya admin yang bisa memperpanjang user yang masih punya invoice terbuka.';
        } else if (jumlah === 1) {
          catatan = 'Pilih dulu salah satu supaya invoice ikut ditutup.';
        }
        invNote.textContent = catatan;
        extendBtn.disabled = jumlah > 0;
        setConfirmExtend();
      }

      invRadios.forEach(r => r.addEventListener('change', () => {
        extendBtn.disabled = false;
        invNote.textContent = '';
        setConfirmExtend();
        extendForm.dataset.zcConfirmed = '';
      }));

      function previewNewExpiry(expireAtIso, days) {
        const now     = new Date();
        const cur     = expireAtIso ? new Date(expireAtIso.replace(' ', 'T')) : null;
        const isValid = cur && !isNaN(cur);
        const expired = !isValid || cur <= now;
        const next    = new Date((expired ? now : cur).getTime());
        next.setDate(next.getDate() + days);
        return { date: next, fromExpired: expired };
      }

      function fmtDate(d) {
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
      }

      function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

      function fillSession(elId, value, fallback) {
        const el = document.getElementById(elId);
        el.textContent = value || fallback;
        el.classList.toggle('t-mute', !value);
      }

      function openDrawer(row) {
        const ds = row.dataset;
        const username = ds.username || '';
        const paket    = ds.paket    || '';
        const expire   = ds.expire   || '';
        const remain   = ds.remain   || '';
        const status   = ds.status   || 'active';
        const ip       = ds.ip       || '';
        const mac      = ds.mac      || '';
        const rx       = ds.rx       || '';
        const tx       = ds.tx       || '';
        const lastLog  = ds.lastLogin|| '';
        const isOnline = ds.online === '1';

        const av = document.getElementById('dr-avatar');
        av.textContent = (username[0] || 'A').toUpperCase();

        document.getElementById('dr-name').textContent = username;
        document.getElementById('dr-sub').textContent  = '@' + username.toLowerCase() + ' / ' + paket;

        const statusRow = document.getElementById('dr-status-row');
        let statusBadge = '';
        let statusNote  = '';
        if (status === 'active') {
          statusBadge = '<span class="badge ok">Aktif</span>';
          statusNote  = isOnline ? 'Sesi online sekarang' : 'Akun aktif';
        } else if (status === 'expired') {
          statusBadge = '<span class="badge err">Expired</span>';
          statusNote  = 'Perlu diperpanjang';
        } else {
          statusBadge = '<span class="badge warn">Nonaktif</span>';
          statusNote  = 'Diblokir admin';
        }
        statusRow.innerHTML = statusBadge + '<span>' + escapeHtml(statusNote) + '</span>';

        document.getElementById('dr-username').textContent = username;
        document.getElementById('dr-paket').textContent    = paket;
        document.getElementById('dr-expire').textContent   = expire;
        document.getElementById('dr-remain').textContent   = remain;

        fillSession('dr-ip',   ip,                       'Belum pernah login');
        fillSession('dr-mac',  mac,                      '');
        fillSession('dr-last', lastLog,                  '');
        fillSession('dr-rxtx', (rx && tx) ? rx + ' / ' + tx : '', '');

        document.getElementById('dr-edit').href   = editTpl.replace('__NAME__', encodeURIComponent(username));
        document.getElementById('dr-print').href  = voucherTpl.replace('__NAME__', encodeURIComponent(username));

        extendForm.action = extendTpl.replace('__NAME__', encodeURIComponent(username));
        const preview  = previewNewExpiry(ds.expireAt || '', EXTEND_DAYS);
        const curLabel = (ds.expireAt && expire !== 'Tidak ada') ? expire : 'belum ada';
        extendBase =
          'Perpanjang masa aktif ' + username + ' selama ' + EXTEND_DAYS + ' hari?\n\n' +
          'Expire sekarang: ' + curLabel + '\n' +
          'Jadi: ' + fmtDate(preview.date) +
          (preview.fromExpired ? '\n\nMasa aktif sudah lewat, dihitung dari hari ini.' : '');
        isiInvoice(ds);
        extendForm.dataset.zcConfirmed = '';

        lastRow = row;
        drawer.classList.add('open');
        overlay.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
        closeBtn.focus({ preventScroll: true });
      }

      let lastRow = null;
      function closeDrawer() {
        if (!drawer.classList.contains('open')) return;
        drawer.classList.remove('open');
        overlay.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        if (lastRow && document.body.contains(lastRow)) lastRow.focus({ preventScroll: true });
      }

      document.addEventListener('click', (e) => {
        const row = e.target.closest('.user-row');
        if (!row) return;
        if (e.target.closest('button, a, form, .tbl-actions')) return;
        openDrawer(row);
      });

      document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const row = e.target.closest && e.target.closest('.user-row');
        if (!row || e.target !== row) return;
        e.preventDefault();
        openDrawer(row);
      });

      closeBtn.addEventListener('click', closeDrawer);
      overlay.addEventListener('click', closeDrawer);
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

      document.addEventListener('live-search:loaded', bindStatusPicks);
    })();
  </script>
@endsection

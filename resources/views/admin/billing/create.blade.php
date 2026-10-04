@extends('layouts.app')

@section('title', 'Buat Tagihan')
@section('page-title', 'Buat Tagihan')

@section('page-class', 'pg-admin-billing-create')

@section('content')
  <header class="page-head">
    <div>
      <h2>Buat Tagihan Baru</h2>
      <p>Buat invoice perpanjangan paket bulanan untuk pelanggan.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('billing.index') }}" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  @if ($errors->any())
    <div class="mb-4"><x-admin.alert type="error" :message="$errors->first()"/></div>
  @endif

  <div class="bill-create-grid">
    <form method="POST" action="{{ route('billing.store') }}" class="card" style="padding: 22px;">
      @csrf

      <div class="form-row">
        <div class="field" style="flex:1.2;">
          <label for="username">Username pelanggan <span class="req">*</span></label>
          <input id="username" name="username" type="text" class="input"
                 value="{{ old('username', $username) }}" required autocomplete="off"
                 placeholder="contoh: admin">
          @error('username') <div class="err-text">{{ $message }}</div> @enderror
        </div>
      </div>

      @if ($hint && $hint['exists'])
        <div class="user-hint user-hint--ok">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
          <div>
            <b>User valid</b><br>
            <span class="muted">
              {{ $hint['contact']?->name ?? 'Belum ada nama kontak' }}
              · {{ $hint['contact']?->phone ?? 'belum ada no HP' }}
              · berlaku sampai {{ $hint['expiry']?->translatedFormat('d M Y') ?? '' }}
            </span>
          </div>
        </div>
      @elseif ($hint && !$hint['exists'])
        <div class="user-hint user-hint--err">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <div>User tidak ditemukan di database radcheck.</div>
        </div>
      @endif

      <div class="form-row">
        <div class="field grow">
          <label for="amount">Jumlah <span class="req">*</span></label>
          <div class="input-prefix">
            <span class="prefix">Rp</span>
            <input id="amount" name="amount" type="number" class="input" min="1000" step="1000"
                   value="{{ old('amount', 200000) }}" required>
          </div>
          @error('amount') <div class="err-text">{{ $message }}</div> @enderror
        </div>
        <div class="field grow">
          <label for="due_date">Jatuh Tempo <span class="req">*</span></label>
          <input id="due_date" name="due_date" type="date" class="input"
                 value="{{ old('due_date', now()->addDays(7)->toDateString()) }}" required>
          @error('due_date') <div class="err-text">{{ $message }}</div> @enderror
        </div>
      </div>

      <div class="quick-prices">
        <span class="quick-prices-label">Harga cepat:</span>
        <button type="button" class="chip" onclick="document.getElementById('amount').value=200000">Rp 200rb</button>
        <button type="button" class="chip" onclick="document.getElementById('amount').value=175000">Rp 175rb (promo)</button>
        <button type="button" class="chip" onclick="document.getElementById('amount').value=170000">Rp 170rb (promo khusus)</button>
      </div>

      <div class="form-row">
        <div class="field grow">
          <label for="profile">Profile</label>
          <input id="profile" name="profile" type="text" class="input"
                 value="{{ old('profile', \App\Services\InvoiceService::DEFAULT_PROFILE) }}">
        </div>
      </div>

      <div class="form-row">
        <div class="field grow">
          <label for="notes">Catatan <span class="muted-label">(opsional)</span></label>
          <textarea id="notes" name="notes" rows="2" class="textarea" style="min-height:64px;"
                    placeholder="Catatan internal, tidak dilihat pelanggan">{{ old('notes') }}</textarea>
        </div>
      </div>

      <div class="form-actions">
        <a href="{{ route('billing.index') }}" class="btn">Batal</a>
        <button class="btn btn-primary" type="submit">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Simpan & Kirim ke Pelanggan
        </button>
      </div>
    </form>

    <aside class="bill-helper">
      <div class="card" style="padding: 18px;">
        <h3 style="margin: 0 0 12px; font-size: 14px; font-weight:600; display:flex; align-items:center; gap:8px;">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--brand-3);"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
          Apa yang akan terjadi?
        </h3>
        <ol style="margin:0; padding-left: 20px; font-size:13px; color:var(--text-2); line-height:1.7;">
          <li>Invoice tersimpan dengan status <b class="t-warn">Belum Bayar</b></li>
          <li>Pelanggan otomatis terima notifikasi WA berisi tagihan & link bayar
            @if (!config('services.billing.wa_notifications'))
              <br><span style="color:var(--err); font-size:11.5px;">⚠ WA notif sedang nonaktif (BILLING_WA_NOTIFICATIONS=false)</span>
            @endif
          </li>
          <li>Pelanggan login ke <code style="background:var(--bg-mute); padding:1px 6px; border-radius:4px;">{{ config('app.domain') }}</code></li>
          <li>Pilih rekening, transfer manual, upload bukti</li>
          <li>Kamu konfirmasi lunas → <b class="t-ok">akun otomatis +30 hari</b></li>
        </ol>
      </div>

      <div class="card" style="padding: 18px; margin-top: 14px;">
        <h3 style="margin: 0 0 12px; font-size: 14px; font-weight:600;">Rekening yang akan ditampilkan</h3>
        <div style="font-size:12.5px; color:var(--text-2);">
          @forelse ((array) config('services.billing.rekening', []) as $b)
            <div style="padding:6px 0;{{ $loop->last ? '' : ' border-bottom:1px dashed var(--border);' }}">
              <b class="t-ink">{{ $b['bank'] }}</b> · {{ $b['account'] }}<br>
              <span class="t-mute">a.n. {{ $b['holder'] }}</span>
            </div>
          @empty
            <div style="padding:6px 0;" class="t-mute">Belum ada rekening. Isi <code>BILLING_REKENING</code> di <code>.env</code>.</div>
          @endforelse
        </div>
      </div>
    </aside>
  </div>

@endsection

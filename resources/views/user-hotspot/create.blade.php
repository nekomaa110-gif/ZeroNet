@extends('layouts.app')

@section('title', 'Tambah User Hotspot')
@section('page-title', 'Tambah User Hotspot')

@section('page-class', 'pg-user-hotspot-create')

@section('content')

  <header class="page-head">
    <div>
      <h2>Tambah User Hotspot</h2>
      <p>Buat akun baru (sinkron langsung ke RADIUS database).</p>
    </div>
    <div class="head-actions">
      <a class="btn" href="{{ route('user-hotspot.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  <form method="POST" action="{{ route('user-hotspot.store') }}"
        x-data="userForm()"
        style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:flex-start">
    @csrf

    <div style="display:flex;flex-direction:column;gap:16px">

      <div class="card">
        <div class="card-head">
          <div class="head-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
          </div>
          <div>
            <h3>Identitas Akun</h3>
            <p class="sub-note">Username unik, akan dipakai untuk login hotspot.</p>
          </div>
        </div>
        <div class="card-pad stack-14">

          <div class="field">
            <label>Username <span class="req">*</span></label>
            <div class="input-group">
              <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
              <input type="text" name="username" x-model="username" value="{{ old('username') }}"
                     autocomplete="off" class="input mono" placeholder="contoh: user01">
            </div>
            @error('username') <small class="err-line">{{ $message }}</small> @enderror
            <small class="hint">Huruf kecil, angka, strip, dan titik. Tidak boleh duplikat.</small>
          </div>

          <div class="field">
            <label>Password <span class="req">*</span></label>
            <div class="input-group rel">
              <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <input :type="showPw ? 'text' : 'password'" id="password" name="password" x-model="password"
                     autocomplete="new-password" class="input mono" style="padding-right:40px">
              <button type="button" @click="showPw = !showPw" tabindex="-1"
                      style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:transparent;border:0;color:var(--text-3);padding:6px;cursor:pointer">
                <svg x-show="!showPw" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg x-show="showPw" x-cloak width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
              <small style="color:var(--text-3);font-size:11.5px;flex:1">Password bebas panjang, minimal 1 karakter.</small>
              <button type="button" class="btn btn-sm btn-ghost" style="padding:4px 10px;font-size:12px" @click="generatePw()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="margin-right:4px;vertical-align:-2px"><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/></svg>
                Generate
              </button>
            </div>
            @error('password') <small class="err-line">{{ $message }}</small> @enderror
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div class="head-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          </div>
          <div>
            <h3>Paket &amp; Masa Berlaku</h3>
            <p class="sub-note">Tentukan profil RADIUS dan tanggal kedaluwarsa.</p>
          </div>
        </div>
        <div class="card-pad stack-14">

          <div class="field">
            <label>Profil / Paket</label>
            <select id="group" name="group" x-model="paket" class="select">
              <option value="">(Tidak ada)</option>
              @foreach ($groups as $g)
                <option value="{{ $g }}" {{ old('group') === $g ? 'selected' : '' }}>{{ $g }}</option>
              @endforeach
            </select>
            @if ($groups->isEmpty())
              <small style="color:var(--warn);font-size:11.5px;display:block;margin-top:4px">
                Belum ada paket. <a href="{{ route('packages.index') }}" style="text-decoration:underline">Buat paket dahulu →</a>
              </small>
            @else
              <small class="hint">Profil mengatur rate-limit, time-limit, &amp; atribut RADIUS.</small>
            @endif
          </div>

          <div class="field">
            <label>Tanggal Expire</label>
            <input type="date" id="expiry" name="expiry" x-model="expiry" value="{{ old('expiry') }}" class="input">
            @error('expiry') <small class="err-line">{{ $message }}</small> @enderror
            <small class="hint">Kosongkan jika tidak ada expire. Waktu otomatis disimpan sebagai <span class="mono">23:59:59</span>.</small>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" style="margin-right:6px;vertical-align:-2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Simpan User
        </button>
        <a href="{{ route('user-hotspot.index') }}" class="btn">Batal</a>
      </div>
    </div>

    <aside style="display:flex;flex-direction:column;gap:16px;position:sticky;top:84px">
      <div class="card card-pad" style="background:linear-gradient(135deg, color-mix(in srgb,var(--brand-1) 8%,var(--bg-elev)), var(--bg-elev))">
        <h4 style="margin:0 0 12px;font-size:13px;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Preview</h4>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
          <div class="avatar" style="width:42px;height:42px" x-text="(username || 'U')[0].toUpperCase()"></div>
          <div class="min0">
            <div style="font-weight:600;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="username || 'user01'"></div>
            <div class="mono hint">akan dibuat</div>
          </div>
        </div>
        <div class="kvp"><span class="k">Profil</span><span class="v" x-text="paket || '(Tidak ada)'"></span></div>
        <div class="kvp"><span class="k">Status</span><span class="v"><span class="badge ok">Akan Aktif</span></span></div>
        <div class="kvp"><span class="k">Expire</span><span class="v" x-text="expiry || 'Tidak ada'"></span></div>
      </div>

      <div class="card card-pad" style="background:color-mix(in srgb,var(--info) 6%,transparent);border:1px solid color-mix(in srgb,var(--info) 30%,var(--border))">
        <div style="display:flex;align-items:flex-start;gap:10px">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--info);flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <div>
            <h4 style="margin:0 0 6px;font-size:13px">Tips</h4>
            <ul style="margin:0;padding-left:16px;color:var(--text-2);font-size:12.5px;line-height:1.7">
              <li>Kirim kredensial via WhatsApp Gateway setelah disimpan</li>
              <li>User dengan expire akan otomatis di-disable</li>
              <li>Profil bisa diubah kapan saja</li>
            </ul>
          </div>
        </div>
      </div>
    </aside>
  </form>


@endsection

@push('scripts')
<script>
function userForm() {
  return {
    username: '{{ old('username') }}',
    password: '',
    paket: '{{ old('group') }}',
    expiry: '{{ old('expiry') }}',
    showPw: false,
    generatePw() {
      const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
      let p = '';
      for (let i = 0; i < 8; i++) p += chars[Math.floor(Math.random() * chars.length)];
      this.password = p;
      this.showPw = true;
    },
  };
}
</script>
@endpush

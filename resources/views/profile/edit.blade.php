@extends('layouts.app')

@section('title', 'Profil Saya')
@section('page-title', 'Profil Saya')

@section('page-class', 'pg-profile-edit')

@section('content')

  <header class="page-head">
    <div>
      <h2>Profil Saya</h2>
      <p>Kelola informasi akun dan keamanan login Anda.</p>
    </div>
  </header>

  @php
    $lastLoginIp = optional(
      \App\Models\ActivityLog::where('user_id', $user->id)->where('action', 'login')->latest()->first()
    )->ip_address;
    $userInitial = strtoupper(substr($user->name ?? $user->username, 0, 1));
  @endphp

  <section class="profile-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px">

    <div class="card">
      <div class="card-head">
        <div class="avatar" style="width:36px;height:36px">{{ $userInitial }}</div>
        <div>
          <h3>Informasi Akun</h3>
          <p class="sub-note">Nama dan username yang tampil di panel.</p>
        </div>
      </div>

      @if(session('success_info'))
        <div class="card-pad" style="padding-bottom:0">
          <x-admin.alert type="success" :message="session('success_info')"/>
        </div>
      @endif

      <form method="POST" action="{{ route('profile.update-info') }}" class="card-pad stack-14">
        @csrf @method('PATCH')

        <div class="field">
          <label>Nama Lengkap <span class="req">*</span></label>
          <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                 placeholder="Nama lengkap Anda" class="input">
          @error('name') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Username <span class="req">*</span></label>
          <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}"
                 placeholder="username" class="input mono">
          @error('username') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Role</label>
          <input class="input" value="{{ ucfirst($user->role) }}" disabled style="opacity:.7" />
        </div>

        <div style="display:flex;justify-content:flex-start">
          <button type="submit" class="btn btn-primary">Simpan Informasi</button>
        </div>
      </form>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="head-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div>
          <h3>Ubah Password</h3>
          <p class="sub-note">Gunakan password yang kuat dan unik.</p>
        </div>
      </div>

      @if(session('success_password'))
        <div class="card-pad" style="padding-bottom:0">
          <x-admin.alert type="success" :message="session('success_password')"/>
        </div>
      @endif

      <form method="POST" action="{{ route('profile.update-password') }}" class="card-pad stack-14">
        @csrf @method('PATCH')

        <div class="field">
          <label>Password Saat Ini <span class="req">*</span></label>
          <input type="password" name="current_password" id="current_password" class="input" placeholder="••••••••">
          @error('current_password') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Password Baru <span class="req">*</span></label>
          <input type="password" name="password" id="password" class="input" placeholder="••••••••">
          @error('password') <small class="err-msg">{{ $message }}</small> @enderror
          <small class="hint">Minimal 8 karakter.</small>
        </div>

        <div class="field">
          <label>Konfirmasi Password Baru <span class="req">*</span></label>
          <input type="password" name="password_confirmation" id="password_confirmation" class="input" placeholder="••••••••">
        </div>

        <div style="display:flex;justify-content:flex-start">
          <button type="submit" class="btn btn-primary">Ubah Password</button>
        </div>
      </form>
    </div>
  </section>

  <section class="profile-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <div class="card">
      <div class="card-head">
        <h3>Info Sesi</h3>
        <p class="sub-note">Detail waktu akses akun ini.</p>
      </div>
      <div class="card-pad">
        <div class="kvp">
          <span class="k">Login Terakhir</span>
          <span class="v">{{ $user->last_login_at ? $user->last_login_at->locale('id')->diffForHumans() : '' }}</span>
        </div>
        <div class="kvp">
          <span class="k">Akun Dibuat</span>
          <span class="v mono">{{ $user->created_at->format('d M Y') }}</span>
        </div>
        <div class="kvp">
          <span class="k">Role</span>
          <span class="v">
            @if($user->role === 'admin')
              <span class="badge brand">Admin</span>
            @else
              <span class="badge info">Operator</span>
            @endif
          </span>
        </div>
        <div class="kvp">
          <span class="k">IP Login Terakhir</span>
          <span class="v mono">{{ $lastLoginIp ?: '' }}</span>
        </div>
      </div>
    </div>

    <div class="card card-pad" style="display:flex;flex-direction:column;justify-content:center;gap:12px">
      <h3 style="margin:0;font-size:14px">Tips Keamanan</h3>
      <ul style="margin:0;padding-left:18px;color:var(--text-2);font-size:13px;line-height:1.7">
        <li>- Aktifkan 2FA untuk lapisan keamanan tambahan</li>
        <li>- Jangan bagikan akses panel ke pihak yang tidak berwenang</li>
        <li>- Ganti password setiap 90 hari</li>
        <li>- Periksa log aktivitas secara berkala</li>
      </ul>
    </div>
  </section>


@endsection

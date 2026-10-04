@extends('layouts.app')

@section('title', 'Two-Factor Authentication')
@section('page-title', 'Two-Factor Authentication')

@section('page-class', 'pg-profile-two-factor')

@section('content')

  <header class="page-head">
    <div>
      <h2>Two-Factor Authentication (2FA)</h2>
      <p>Lindungi akun dengan kode dari aplikasi authenticator.</p>
    </div>
    <div class="head-actions">
      @if ($user->hasTwoFactorEnabled())
        <span class="badge ok">Aktif</span>
      @else
        <span class="badge">Nonaktif</span>
      @endif
    </div>
  </header>

  @if (! $user->hasTwoFactorEnabled() && in_array($user->role, (array) config('auth.two_factor_required_roles', []), true))
    <div style="margin-bottom:16px">
      <x-admin.alert type="warning" message="Akun dengan role {{ $user->role }} wajib memakai 2FA. Aktifkan dulu di bawah ini, setelah itu menu panel lain bisa dibuka lagi."/>
    </div>
  @endif

  @if(session('success_2fa'))
    <div class="mb-4">
      <x-admin.alert type="success" :message="session('success_2fa')"/>
    </div>
  @endif

  @if ($user->hasTwoFactorEnabled())

    <div class="card mb-4">
      <div class="card-head">
        <div class="head-icon tone-ok">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <h3>2FA Aktif</h3>
          <p class="sub-note">
            Aktif sejak <b class="mono">{{ $user->two_factor_confirmed_at->translatedFormat('d M Y H:i') }}</b>.
            Setiap login akan meminta kode 6 digit dari authenticator Anda.
          </p>
        </div>
      </div>
    </div>

    <section class="twofa-pair" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <div class="card">
        <div class="card-head">
          <h3>Regenerate Recovery Codes</h3>
          <p class="sub-note">Code lama akan tidak berlaku.</p>
        </div>
        <form method="POST" action="{{ route('two-factor.regenerate-codes') }}" class="card-pad" style="display:flex;flex-direction:column;gap:12px">
          @csrf
          <div class="field">
            <label>Password saat ini <span class="req">*</span></label>
            <input type="password" name="current_password" required placeholder="••••••••" class="input">
            @error('current_password') <small class="err-msg">{{ $message }}</small> @enderror
          </div>
          <button type="submit" class="btn btn-primary">Regenerate</button>
        </form>
      </div>

      <div class="card" style="border-color:color-mix(in srgb,var(--err) 30%,var(--border))">
        <div class="card-head">
          <h3 class="t-err">Nonaktifkan 2FA</h3>
          <p class="sub-note">Hapus 2FA (login hanya menggunakan username dan password).</p>
        </div>
        <form method="POST" action="{{ route('two-factor.disable') }}"
              data-confirm="Yakin nonaktifkan 2FA untuk akun Anda? Setelah ini, login hanya butuh username + password, akun jadi lebih rentan."
              data-confirm-title="Nonaktifkan 2FA"
              data-confirm-action="Ya, Matikan 2FA"
              data-confirm-variant="warning"
              class="card-pad" style="display:flex;flex-direction:column;gap:12px;background:color-mix(in srgb,var(--err) 4%,transparent)">
          @csrf @method('DELETE')
          <div class="field">
            <label>Password saat ini <span class="req">*</span></label>
            <input type="password" name="current_password" required placeholder="••••••••" class="input">
          </div>
          <button type="submit" class="btn btn-danger">Nonaktifkan</button>
        </form>
      </div>
    </section>

  @else

    <div style="max-width: 760px;">
      <div class="card">
        <div class="card-head">
          <h3>Langkah-langkah</h3>
        </div>
        <div class="card-pad" style="padding-bottom: 12px;">
          <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px;font-size:12.5px;color:var(--text-2)">
            <li>Install aplikasi authenticator (Google Authenticator, Authy, Microsoft Authenticator).</li>
            <li>Scan QR code di bawah, atau masukkan secret key secara manual.</li>
            <li>Masukkan kode 6 digit yang muncul di aplikasi untuk konfirmasi.</li>
          </ol>
        </div>

        <div class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:24px;align-items:start;">
          <div style="display:flex;flex-direction:column;align-items:center;gap:10px;">
            <div style="padding:12px;background:#fff;border:1px solid var(--border);border-radius:var(--r-lg);">
              {!! $qrSvg !!}
            </div>
            <p style="font-size:11.5px;color:var(--text-3);text-align:center;margin:0">Scan dengan aplikasi authenticator</p>
          </div>

          <div class="stack-14">
            <div class="field">
              <label>Secret Key (input manual)</label>
              <div style="display:flex;align-items:stretch;gap:8px">
                <input type="text" readonly value="{{ $secret }}" class="input mono" style="background:var(--bg-mute);flex:1">
                <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $secret }}'); this.innerText='Tersalin'; setTimeout(() => this.innerText='Salin', 1500);"
                        class="btn btn-sm">Salin</button>
              </div>
            </div>

            @if ($errors->any())
              <x-admin.alert type="error" :message="$errors->first()"/>
            @endif

            <form class="stack-14" method="POST" action="{{ route('two-factor.confirm') }}">
              @csrf
              <div class="field">
                <label>Kode dari Authenticator <span class="req">*</span></label>
                <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                       maxlength="6" autocomplete="one-time-code" required autofocus
                       placeholder="123456"
                       class="input mono" style="text-align:center;font-size:18px;letter-spacing:8px">
              </div>
              <div style="display:flex;gap:10px;align-items:center;">
                <button type="submit" class="btn btn-primary">Konfirmasi & Aktifkan</button>
                <a href="{{ route('profile.edit') }}" class="btn">Batal</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

  @endif


@endsection

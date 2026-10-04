<!doctype html>
<html lang="id">
<head>
  @include('auth._head')
  <title>Konfirmasi password · {{ config('app.name', 'ZeroNet') }}</title>
</head>
<body>
  <main class="auth-main">
    <div class="auth-card boxed">
      <div class="auth-head">
        <div class="auth-mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <h2>Konfirmasi password</h2>
          <p>Aksi ini sensitif. Masukkan lagi password Anda untuk melanjutkan.</p>
        </div>
      </div>

      @if ($errors->any())
        <div class="auth-alert err" role="alert">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('password.confirm') }}" autocomplete="off">
        @csrf
        <div class="field">
          <label for="password">Password</label>
          <input class="input" id="password" name="password" type="password" required autofocus autocomplete="current-password" />
        </div>
        <div class="inline-actions">
          <a href="{{ url()->previous() }}" class="btn">Batal</a>
          <button type="submit" class="btn btn-primary">Konfirmasi</button>
        </div>
      </form>
    </div>
  </main>
</body>
</html>

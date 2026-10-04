<!doctype html>
<html lang="id">
<head>
  @include('auth._head')
  <title>Masuk panel · {{ config('app.name', 'ZeroNet') }}</title>
</head>
<body>
  <div class="auth-split">
    <aside class="auth-rack">
      <div class="auth-brand"><svg viewBox="0 0 200 200" fill="none" aria-hidden="true"><polygon points="10,55 100,8 190,55 190,145 100,192 10,145" fill="none" stroke="currentColor" stroke-width="10" stroke-linejoin="round"/><rect x="48" y="60" width="18" height="80" rx="3" fill="currentColor"/><rect x="134" y="60" width="18" height="80" rx="3" fill="currentColor"/><path d="M 66,62 L 134,138 L 134,118 L 66,82 Z" fill="currentColor"/></svg><b>ZeroNet</b></div>
      <div class="auth-claim">
        <h1>Panel operator jaringan hotspot.</h1>
        <p>Router, user RADIUS, voucher, tagihan, dan WhatsApp dalam satu tempat.</p>
      </div>
      <div class="auth-meta">
        <span><span class="led ok" aria-hidden="true"></span>{{ request()->getHost() }}</span>
        <span>akses khusus operator</span>
      </div>
    </aside>

    <main class="auth-main">
      <div class="auth-card">
        <h2>Masuk</h2>
        <p class="lead">Gunakan akun operator Anda.</p>

        @if ($errors->any())
          <div class="auth-alert err" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>{{ $errors->first() }}</span>
          </div>
        @endif

        @if (session('status'))
          <div class="auth-alert ok" role="status">{{ session('status') }}</div>
        @endif

        <form id="login-form" method="POST" action="{{ route('login') }}" autocomplete="off">
          @csrf

          <div class="field">
            <label for="login">Username</label>
            <input class="input" id="login" name="login" type="text"
                   value="{{ old('login') }}" required autofocus
                   autocomplete="off" autocapitalize="none" spellcheck="false" />
          </div>

          <div class="field">
            <label for="password">Password</label>
            <div class="pw">
              <input class="input" id="password" name="password" type="password"
                     required autocomplete="new-password" />
              <button type="button" class="eye" id="pw-toggle" aria-label="Tampilkan password" aria-pressed="false">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>

          <div class="row">
            <label><input id="remember" type="checkbox" name="remember" /> Ingat username di perangkat ini</label>
          </div>

          <button type="submit" class="btn btn-primary submit" id="login-submit"
                  @if (session('lockout_seconds')) disabled @endif
                  data-label="Masuk">Masuk</button>
        </form>

        <div class="auth-foot">ZeroNet {{ date('Y') }}</div>
      </div>
    </main>
  </div>

  <script>
    (function () {
      var remaining = {{ (int) session('lockout_seconds', 0) }};
      if (remaining <= 0) return;

      var btn   = document.getElementById('login-submit');
      var label = btn.getAttribute('data-label');

      function render() {
        if (remaining <= 0) {
          btn.disabled = false;
          btn.textContent = label;
          return;
        }
        btn.textContent = 'Coba lagi dalam ' + remaining + ' detik';
        remaining--;
        setTimeout(render, 1000);
      }

      btn.disabled = true;
      render();
    })();

    document.getElementById('pw-toggle').addEventListener('click', function () {
      var i = document.getElementById('password');
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      this.setAttribute('aria-pressed', show ? 'true' : 'false');
      this.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    });

    (function () {
      var STORAGE_KEY = 'zeronet_remember_login';
      var loginInput  = document.getElementById('login');
      var rememberBox = document.getElementById('remember');
      var form        = document.getElementById('login-form');

      var saved = null;
      try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) {}
      if (saved) {
        if (!loginInput.value) loginInput.value = saved;
        rememberBox.checked = true;
      }

      form.addEventListener('submit', function () {
        try {
          if (rememberBox.checked && loginInput.value.trim()) {
            localStorage.setItem(STORAGE_KEY, loginInput.value.trim());
          } else {
            localStorage.removeItem(STORAGE_KEY);
          }
        } catch (e) {}
      });
    })();
  </script>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
  @include('auth._head', ['withAlpine' => true])
  <title>Verifikasi 2 langkah · {{ config('app.name', 'ZeroNet') }}</title>
</head>
<body>
  <main class="auth-main">
    <div class="auth-card boxed" x-data="{ mode: 'totp' }">
      <div class="auth-head">
        <div class="auth-mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div>
          <h2>Verifikasi 2 langkah</h2>
          <p x-show="mode === 'totp'">Masukkan kode 6 digit dari aplikasi authenticator.</p>
          <p x-show="mode === 'recovery'" x-cloak>Masukkan salah satu recovery code Anda.</p>
        </div>
      </div>

      @if ($errors->any())
        <div class="auth-alert err" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span>{{ $errors->first() }}</span>
        </div>
      @endif

      <form id="otp-form" method="POST" action="{{ route('two-factor.verify') }}">
        @csrf

        <div x-show="mode === 'totp'">
          <div class="otp-grid" id="otp" role="group" aria-label="Kode 6 digit">
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" aria-label="Digit 1" autofocus />
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit 2" />
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit 3" />
            <span class="sep" aria-hidden="true"></span>
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit 4" />
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit 5" />
            <input class="otp-input" maxlength="1" inputmode="numeric" pattern="[0-9]" aria-label="Digit 6" />
          </div>
          <input type="hidden" name="code" id="code-hidden" :disabled="mode !== 'totp'">
          <div class="otp-help">
            <span>Kode berganti setiap 30 detik</span>
            <span class="timer"><span class="led ok" aria-hidden="true"></span><span id="timer">00:30</span></span>
          </div>
        </div>

        <div x-show="mode === 'recovery'" x-cloak class="field">
          <label for="recovery_code">Recovery code</label>
          <input id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                 placeholder="xxxxx-xxxxx" class="input mono"
                 x-bind:disabled="mode !== 'recovery'" />
        </div>

        <div class="auth-actions">
          <button type="submit" class="btn btn-primary submit">Verifikasi</button>
          <button type="button" class="link-btn" x-show="mode === 'totp'" @click="mode = 'recovery'">Pakai recovery code</button>
          <button type="button" class="link-btn" x-show="mode === 'recovery'" x-cloak @click="mode = 'totp'">Kembali ke kode authenticator</button>
        </div>
      </form>

      <form method="POST" action="{{ route('two-factor.cancel') }}" class="auth-actions">
        @csrf
        <button type="submit" class="link-btn quiet">Batalkan login</button>
      </form>
    </div>
  </main>

  <script>
    const inputs = document.querySelectorAll('#otp .otp-input');
    const hiddenCode = document.getElementById('code-hidden');
    const otpForm = document.getElementById('otp-form');
    let _submitted = false;
    function syncCode() {
      const code = [...inputs].map(i => i.value).join('');
      hiddenCode.value = code;
      if (code.length === inputs.length && /^\d+$/.test(code) && !_submitted) {
        _submitted = true;
        inputs.forEach(i => i.blur());
        setTimeout(() => otpForm.requestSubmit
          ? otpForm.requestSubmit()
          : otpForm.submit(), 120);
      }
    }
    inputs.forEach((inp, i) => {
      inp.addEventListener('input', (e) => {
        const v = e.target.value.replace(/\D/g,'');
        e.target.value = v.slice(0,1);
        e.target.classList.toggle('filled', !!v);
        if (v && i < inputs.length - 1) inputs[i+1].focus();
        syncCode();
      });
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && i > 0) inputs[i-1].focus();
      });
      inp.addEventListener('paste', (e) => {
        e.preventDefault();
        const txt = (e.clipboardData.getData('text') || '').replace(/\D/g,'').slice(0, inputs.length);
        [...txt].forEach((d, idx) => { if (inputs[idx]) { inputs[idx].value = d; inputs[idx].classList.add('filled'); }});
        const next = Math.min(txt.length, inputs.length-1);
        inputs[next].focus();
        syncCode();
      });
    });
    const timerEl = document.getElementById('timer');
    function tickTotp() {
      const secs = 30 - (Math.floor(Date.now() / 1000) % 30);
      timerEl.textContent = '00:' + String(secs).padStart(2, '0');
    }
    tickTotp();
    setInterval(tickTotp, 1000);
  </script>
</body>
</html>

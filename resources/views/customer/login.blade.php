@extends('customer.layout')
@section('title', 'Masuk Pelanggan · ZeroNet')
@section('content-class', 'narrow')


@section('content')
  <div class="page-intro">
    <h1>Portal pelanggan</h1>
    <p>Cek masa aktif dan tagihan. Masuk dengan <b>username dan password voucher</b> bulananmu.</p>
  </div>

  @if ($errors->has('credentials') || $errors->has('throttle'))
    <div class="alert err">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
      <div>
        <strong>{{ $errors->first('credentials') ?: $errors->first('throttle') }}</strong>
        @if ($errors->has('credentials'))
          <div class="sub">Cek lagi huruf besar/kecilnya, harus persis sama dengan yang tertulis di voucher. Ketuk ikon mata untuk melihat password yang kamu ketik.</div>
        @endif
      </div>
    </div>
  @endif

  <div class="card">
    <form method="POST" action="{{ route('customer.login.attempt') }}" novalidate>
      @csrf

      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" inputmode="text" autocomplete="username"
               placeholder="Contoh: namauser123"
               autocapitalize="none" autocorrect="off" spellcheck="false"
               required autofocus value="{{ old('username') }}" />
        @error('username') <div class="err">{{ $message }}</div> @enderror
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="input-affix">
          <input id="password" name="password" type="password" autocomplete="current-password"
                 placeholder="Masukkan password"
                 autocapitalize="none" autocorrect="off" spellcheck="false" required />
          <button type="button" id="togglePassword" class="affix-btn"
                  aria-label="Tampilkan password" aria-pressed="false" aria-controls="password">
            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-hide is-hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.7 6.2A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a17.8 17.8 0 0 1-3.2 4.2M6.3 6.9A17.7 17.7 0 0 0 2 12s3.6 7 10 7a9.8 9.8 0 0 0 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="m3 3 18 18"/></svg>
          </button>
        </div>
        @error('password') <div class="err">{{ $message }}</div> @enderror
      </div>

      <label class="check" for="remember">
        <input id="remember" name="remember" type="checkbox" value="1" {{ old('remember') ? 'checked' : '' }}>
        <span>Ingat saya di HP ini</span>
      </label>

      <button class="btn btn-primary btn-block" type="submit">Masuk</button>
    </form>
  </div>

  <div class="help-line">
    <p>Belum punya akun bulanan atau lupa password?
      <a href="https://wa.me/{{ $businessWa }}?text={{ rawurlencode('Halo admin ZeroNet, saya mau tanya soal akun WiFi bulanan.') }}"
         target="_blank" rel="noopener">Hubungi admin</a>.</p>
  </div>

  <script>
    (function () {
      var input = document.getElementById('password');
      var btn = document.getElementById('togglePassword');
      if (!input || !btn) return;

      var iconShow = btn.querySelector('.icon-show');
      var iconHide = btn.querySelector('.icon-hide');

      btn.addEventListener('click', function () {
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        iconShow.classList.toggle('is-hidden', reveal);
        iconHide.classList.toggle('is-hidden', !reveal);
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        btn.setAttribute('aria-label', reveal ? 'Sembunyikan password' : 'Tampilkan password');
        input.focus();
        var v = input.value;
        input.value = '';
        input.value = v;
      });
    })();
  </script>
@endsection

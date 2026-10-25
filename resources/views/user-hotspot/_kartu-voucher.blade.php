  <div class="voucher">
    <div class="brand">{{ config('site.brand') }}</div>
    <div class="tagline">{{ config('site.tagline') }}</div>

    <div class="rule"></div>

    <div class="fields">
      <div class="field">
        <div class="k">Username</div>
        <div class="v">{{ $user['username'] }}</div>
      </div>

      <div class="field">
        <div class="k">Password</div>
        <div class="v">{{ $user['password'] }}</div>
      </div>
    </div>

    <div class="foot">
      Simpan kartu ini. Jangan dibagikan ke orang lain.
    </div>
  </div>

@extends('layouts.app')

@section('title', 'Recovery Codes 2FA')
@section('page-title', 'Recovery Codes 2FA')

@section('content')

  <header class="page-head">
    <div>
      <h2>Recovery Codes</h2>
      <p>Simpan kode-kode ini di tempat aman. Setiap kode hanya bisa digunakan satu kali.</p>
    </div>
  </header>

  <div style="max-width: 760px;">
    <div class="mb-4">
      <x-admin.alert type="warning" message="Kode ini hanya ditampilkan satu kali. Simpan baik-baik (gunakan untuk login jika HP/aplikasi authenticator hilang)."/>
    </div>

    <div class="card">
      <div class="card-head">
        <h3>{{ count($codes) }} Recovery Codes</h3>
        <div class="ch-actions">
          <button type="button"
                  onclick="navigator.clipboard.writeText(document.getElementById('codes-block').innerText); this.innerText='Tersalin'; setTimeout(() => this.innerText='Salin Semua', 1500);"
                  class="btn btn-sm">Salin Semua</button>
        </div>
      </div>

      <div id="codes-block" class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
        @foreach ($codes as $code)
          <div class="mono" style="padding:9px 12px;border-radius:var(--r-md);background:var(--bg-mute);border:1px solid var(--border);text-align:center;user-select:all;font-size:13px">{{ $code }}</div>
        @endforeach
      </div>

      <div style="padding:14px var(--pad-card);border-top:1px solid var(--border)">
        <a href="{{ route('two-factor.index') }}" class="btn btn-primary">Saya sudah simpan</a>
      </div>
    </div>
  </div>

@endsection

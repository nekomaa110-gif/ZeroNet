@extends('layouts.app')

@php
  $isEdit = ($mode ?? 'create') === 'edit';
  $title  = $isEdit ? 'Edit Operator' : 'Tambah Operator';
  $isSelf = $isEdit && $user->id === auth()->id();
@endphp

@section('title', $title)
@section('page-title', $title)

@section('content')

  <header class="page-head">
    <div>
      <h2>{{ $title }}</h2>
      <p>{{ $isEdit ? 'Ubah informasi akun panel.' : 'Buat akun baru untuk admin atau operator panel.' }}</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('operators.index') }}" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  <div style="max-width:760px">
    <form method="POST"
          action="{{ $isEdit ? route('operators.update', $user->id) : route('operators.store') }}"
          class="card">
      @csrf
      @if($isEdit) @method('PATCH') @endif

      <div class="card-head">
        <h3>{{ $isEdit ? 'Detail Akun' : 'Informasi Akun Baru' }}</h3>
      </div>

      <div class="card-pad" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field">
          <label>Nama Lengkap <span class="req">*</span></label>
          <input type="text" name="name" value="{{ old('name', $user->name) }}" placeholder="Nama lengkap" class="input">
          @error('name') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Username <span class="req">*</span></label>
          <input type="text" name="username" value="{{ old('username', $user->username) }}" placeholder="username" class="input mono">
          @error('username') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field span-all">
          <label>Email <span class="req">*</span></label>
          <input type="email" name="email" value="{{ old('email', $user->email) }}" placeholder="email@domain.com" class="input">
          @error('email') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Role <span class="req">*</span></label>
          <select name="role" class="input" {{ $isSelf ? 'disabled' : '' }}>
            <option value="operator" @selected(old('role', $user->role) === 'operator')>Operator</option>
            <option value="admin"    @selected(old('role', $user->role) === 'admin')>Admin</option>
          </select>
          @if($isSelf)
            <input type="hidden" name="role" value="admin">
            <small class="hint">Role akun sendiri tidak bisa diubah dari sini.</small>
          @endif
          @error('role') <small class="err-msg">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Status</label>
          <label style="display:flex;align-items:center;gap:8px;padding:10px 0">
            <input type="checkbox" name="is_active" value="1"
                   {{ old('is_active', $user->is_active ?? true) ? 'checked' : '' }}
                   {{ $isSelf ? 'disabled' : '' }}>
            <span>Aktif (bisa login ke panel)</span>
          </label>
          @if($isSelf)
            <input type="hidden" name="is_active" value="1">
          @endif
          @error('is_active') <small class="err-msg">{{ $message }}</small> @enderror
        </div>
      </div>

      <div class="card-head" style="border-top:1px solid var(--border);margin-top:6px">
        <h3>{{ $isEdit ? 'Reset Password (opsional)' : 'Password' }}</h3>
        @if($isEdit)
          <p class="sub-note">Kosongkan jika tidak ingin mengganti password.</p>
        @endif
      </div>

      <div class="card-pad" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="field">
          <label>Password {{ $isEdit ? '' : '*' }}</label>
          <input type="password" name="password" placeholder="••••••••" class="input"
                 autocomplete="new-password">
          @error('password') <small class="err-msg">{{ $message }}</small> @enderror
          <small class="hint">Minimal 8 karakter.</small>
        </div>

        <div class="field">
          <label>Konfirmasi Password</label>
          <input type="password" name="password_confirmation" placeholder="••••••••" class="input"
                 autocomplete="new-password">
        </div>
      </div>

      <div class="card-pad" style="border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end">
        <a href="{{ route('operators.index') }}" class="btn">Batal</a>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Simpan Perubahan' : 'Buat Operator' }}</button>
      </div>
    </form>
  </div>

@endsection

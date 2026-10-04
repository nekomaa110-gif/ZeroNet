@extends('layouts.app')

@section('title', $isUpdate ? 'Edit Paket: ' . $package->groupname : 'Tambah Paket')
@section('page-title', $isUpdate ? 'Edit Paket' : 'Tambah Paket')

@section('page-class', 'pg-packages-form')

@section('content')

@php
    if (old('attributes') !== null) {
        $initialAttrs = array_values(array_filter(old('attributes'), fn($a) => !empty($a['attribute'])));
    } elseif ($isUpdate) {
        $initialAttrs = $package->attributes->map(fn($a) => [
            'attribute'    => $a->attribute,
            'op'           => $a->op,
            'value'        => $a->value,
            'target_table' => $a->target_table,
        ])->values()->toArray();
    } else {
        $initialAttrs = [];
    }
    if (empty($initialAttrs)) {
        $initialAttrs = [['attribute' => '', 'op' => ':=', 'value' => '', 'target_table' => 'radgroupreply']];
    }
    $isActiveValue = old('is_active', $isUpdate ? ($package->is_active ? 1 : 0) : 1);
    $action = $isUpdate ? route('packages.update', $package->id) : route('packages.store');
@endphp

  <header class="page-head">
    <div>
      <h2>{{ $isUpdate ? 'Edit Paket: ' . $package->groupname : 'Tambah Paket' }}</h2>
      <p>Definisikan profil RADIUS dan atribut yang akan dikirim ke MikroTik.</p>
    </div>
    <div class="head-actions">
      <a class="btn" href="{{ route('packages.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        Kembali
      </a>
    </div>
  </header>

  <form method="POST" action="{{ $action }}" style="display:flex;flex-direction:column;gap:16px;max-width:1100px">
    @csrf
    @if($isUpdate) @method('PUT') @endif

    <div class="card">
      <div class="card-head">
        <div class="head-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div>
          <h3>Informasi Paket</h3>
          <p class="sub-note">Nama paket dipakai sebagai groupname di tabel RADIUS.</p>
        </div>
      </div>
      <div class="card-pad" style="display:grid;grid-template-columns:1.5fr 1fr;gap:18px">

        <div class="field">
          <label>Nama Paket <span class="req">*</span></label>
          @if($isUpdate)
            <input type="text" value="{{ $package->groupname }}" disabled class="input mono"
                   style="background:var(--bg-mute);color:var(--text-3);cursor:not-allowed">
            <input type="hidden" name="groupname" value="{{ $package->groupname }}">
            <small class="hint">Nama paket tidak dapat diubah.</small>
          @else
            <input type="text" id="groupname" name="groupname" value="{{ old('groupname') }}"
                   placeholder="contoh: paket-10mbps" class="input mono">
            @error('groupname') <small class="err-line">{{ $message }}</small> @enderror
            <small class="hint">Huruf, angka, strip, titik, underscore. Dipakai sebagai groupname di RADIUS.</small>
          @endif
        </div>

        <div class="field">
          <label>Status</label>
          <div style="display:flex;align-items:center;gap:12px;padding:9px 0">
            <label class="switch">
              <input type="hidden" name="is_active" value="0">
              <input type="checkbox" name="is_active" value="1" {{ $isActiveValue ? 'checked' : '' }}>
              <span class="track"></span>
            </label>
            <span style="font-size:13.5px;font-weight:500">Aktif</span>
          </div>
          <small class="hint">Aktif: FR verifikasi password normal · Nonaktif: <span class="mono">Auth-Type := Reject</span> (block grup)</small>
        </div>

        <div class="field">
          <label>Nama Tampilan (Pelanggan)</label>
          <input type="text" id="display_name" name="display_name"
                 value="{{ old('display_name', $isUpdate ? $package->display_name : '') }}"
                 placeholder="contoh: Paket Home" class="input">
          @error('display_name') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Tampil di portal pelanggan. Kosongkan untuk memakai nama paket.</small>
        </div>

        <div class="field">
          <label>Kecepatan</label>
          <input type="text" id="speed_label" name="speed_label"
                 value="{{ old('speed_label', $isUpdate ? $package->speed_label : '') }}"
                 placeholder="contoh: 10 Mbps" class="input">
          @error('speed_label') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Hanya label untuk pelanggan, tidak mengubah limit di MikroTik.</small>
        </div>

        <div class="field span-all">
          <label>Deskripsi (internal)</label>
          <input type="text" id="description" name="description"
                 value="{{ old('description', $isUpdate ? $package->description : '') }}"
                 placeholder="contoh: Bulanan DIVISI 3 dan DIVISI 4" class="input">
          @error('description') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Catatan admin. Tidak ditampilkan ke pelanggan.</small>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <div class="head-icon tone-ok">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div>
          <h2>Tampilan Website Publik</h2>
          <p style="margin:0;color:var(--text-3);font-size:12.5px">Dipakai halaman <span class="mono">{{ config('app.domain') }}/paket</span>. Tidak memengaruhi konfigurasi RADIUS.</p>
        </div>
      </div>

      <div class="grid-2">
        <div class="field">
          <label>Tampilkan di Website</label>
          <div style="display:flex;align-items:center;gap:12px;padding:9px 0">
            <label class="switch">
              <input type="hidden" name="is_public" value="0">
              <input type="checkbox" name="is_public" value="1"
                     {{ old('is_public', $isUpdate ? (int) $package->is_public : 0) ? 'checked' : '' }}>
              <span class="track"></span>
            </label>
            <span style="font-size:13.5px;font-weight:500">Tampil untuk calon pelanggan</span>
          </div>
          <small class="hint">Paket hanya muncul di website kalau ini aktif <em>dan</em> status paket Aktif.</small>
        </div>

        <div class="field">
          <label>Harga (Rp)</label>
          <input type="number" id="price" name="price" min="0" max="99999999" step="1000"
                 value="{{ old('price', $isUpdate ? $package->price : '') }}"
                 placeholder="contoh: 150000" class="input">
          @error('price') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Catatan harga. Tetap tersimpan meski tidak ditampilkan di website.</small>
        </div>

        <div class="field">
          <label>Tampilkan Harga di Website</label>
          <div style="display:flex;align-items:center;gap:12px;padding:9px 0">
            <label class="switch">
              <input type="hidden" name="show_price" value="0">
              <input type="checkbox" name="show_price" value="1"
                     {{ old('show_price', $isUpdate ? (int) $package->show_price : 0) ? 'checked' : '' }}>
              <span class="track"></span>
            </label>
            <span style="font-size:13.5px;font-weight:500">Harga terlihat calon pelanggan</span>
          </div>
          <small class="hint">Nonaktif: kartu paket tampil polos tanpa baris harga.</small>
        </div>

        <div class="field">
          <label>Masa Aktif (hari)</label>
          <input type="number" id="validity_days" name="validity_days" min="1" max="3650" step="1"
                 value="{{ old('validity_days', $isUpdate ? $package->validity_days : '') }}"
                 placeholder="contoh: 30" class="input">
          @error('validity_days') <small class="err-line">{{ $message }}</small> @enderror
        </div>

        <div class="field">
          <label>Urutan Tampil</label>
          <input type="number" id="sort_order" name="sort_order" min="0" max="999" step="1"
                 value="{{ old('sort_order', $isUpdate ? $package->sort_order : 0) }}"
                 class="input">
          @error('sort_order') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Angka kecil tampil lebih dulu.</small>
        </div>

        <div class="field span-all">
          <label>Deskripsi Singkat (pelanggan)</label>
          <input type="text" id="public_description" name="public_description" maxlength="255"
                 value="{{ old('public_description', $isUpdate ? $package->public_description : '') }}"
                 placeholder="contoh: Cocok untuk 3-5 perangkat, streaming lancar" class="input">
          @error('public_description') <small class="err-line">{{ $message }}</small> @enderror
          <small class="hint">Tampil di kartu paket. Jangan tulis detail teknis jaringan.</small>
        </div>
      </div>
    </div>

    <div class="card" x-data="packageBuilder({{ \Illuminate\Support\Js::from($initialAttrs) }})">
      <div class="card-head">
        <div class="head-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        </div>
        <div>
          <h3>Atribut RADIUS</h3>
          <p class="sub-note">Atribut dikirim ke tabel RADIUS sesuai kolom Target.</p>
        </div>
        <div class="ch-actions">
          <button type="button" @click="addRow()" class="btn btn-sm btn-primary">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" style="margin-right:4px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Baris
          </button>
        </div>
      </div>

      @php
        $attrErrors = collect($errors->toArray())->filter(fn($v, $k) => str_starts_with($k, 'attributes.'))->flatten();
      @endphp
      @if($attrErrors->isNotEmpty())
        <div style="margin: 14px 22px 0;padding: 10px 12px;border-radius: var(--r-md);background: color-mix(in srgb, var(--err) 8%, transparent);color: var(--err);border: 1px solid color-mix(in srgb, var(--err) 25%, transparent);font-size: 12.5px">
          {{ $attrErrors->first() }}
        </div>
      @endif

      <div class="card-pad">
        <table class="tbl tbl-attr">
          <thead>
            <tr>
              <th style="width:34%">Atribut</th>
              <th style="width:80px">OP</th>
              <th>Nilai</th>
              <th style="width:160px">Target</th>
              <th style="width:40px"></th>
            </tr>
          </thead>
          <tbody>
            <template x-for="(attr, i) in attrs" :key="i">
              <tr>
                <td><input type="text" :name="`attributes[${i}][attribute]`" x-model="attr.attribute"
                           list="attr-presets" class="input mono" placeholder="Mikrotik-Rate-Limit"></td>
                <td>
                  <select :name="`attributes[${i}][op]`" x-model="attr.op" class="input mono">
                    @foreach($operators as $op)
                      <option value="{{ $op }}">{{ $op }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="text" :name="`attributes[${i}][value]`" x-model="attr.value"
                           class="input mono" placeholder="nilai atribut"></td>
                <td>
                  <select :name="`attributes[${i}][target_table]`" x-model="attr.target_table" class="input mono">
                    @foreach($targets as $tbl)
                      <option value="{{ $tbl }}">{{ $tbl }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <button type="button" @click="removeRow(i)" class="icon-btn t-mute" aria-label="Hapus">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <datalist id="attr-presets">
          @foreach($presets as $preset)<option value="{{ $preset }}">@endforeach
        </datalist>

        <p style="margin:14px 0 0;color:var(--text-3);font-size:12px;line-height:1.6">
          Ketik bebas atau pilih dari daftar preset. Target
          <span class="mono muted">radgroupreply/check</span> = atribut grup,
          <span class="mono muted">radreply/check</span> = atribut per-user (username = nama paket).
        </p>
      </div>
    </div>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" style="margin-right:6px;vertical-align:-2px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        {{ $isUpdate ? 'Simpan Perubahan' : 'Buat Paket' }}
      </button>
      <a href="{{ route('packages.index') }}" class="btn">Batal</a>
    </div>
  </form>


@endsection

@push('scripts')
<script>
function packageBuilder(initialAttrs) {
  return {
    attrs: initialAttrs,
    addRow() { this.attrs.push({ attribute: '', op: ':=', value: '', target_table: 'radgroupreply' }); },
    removeRow(i) { this.attrs.splice(i, 1); if (this.attrs.length === 0) this.addRow(); }
  };
}
</script>
@endpush

@extends('layouts.app')

@section('title', 'Area Layanan')
@section('page-title', 'Area Layanan')

@section('content')

  <header class="page-head">
    <div>
      <h2>Area Layanan Website</h2>
      <p>Daftar wilayah yang tampil di halaman publik <span class="mono">{{ config('app.domain') }}/coverage</span>
      </p>
    </div>
    <div class="head-actions">
      <a href="{{ url('/coverage') }}" class="btn" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Lihat Halaman Publik
      </a>
    </div>
  </header>

  @if($errors->any())
    <div class="mb-4"><x-admin.alert type="error" :message="$errors->first()"/></div>
  @endif

  <div class="card mb-4">
    <div class="card-head">
      <h3>Tambah Area</h3>
    </div>

    <form method="POST" action="{{ route('coverage-areas.store') }}" class="card-pad">
      @csrf
      <div class="grid-2">
        <div class="field">
          <label for="name">Nama Area</label>
          <input type="text" id="name" name="name" class="input" required maxlength="120"
                 value="{{ old('name') }}" placeholder="contoh: Desa Sukamaju">
        </div>

        <div class="field">
          <label for="status">Status</label>
          <select id="status" name="status" class="select" required>
            <option value="tersedia" @selected(old('status') === 'tersedia')>Tersedia</option>
            <option value="segera" @selected(old('status') === 'segera')>Segera Hadir</option>
          </select>
        </div>

        <div class="field">
          <label for="note">Keterangan</label>
          <input type="text" id="note" name="note" class="input" maxlength="160"
                 value="{{ old('note') }}" placeholder="contoh: Jaringan aktif">
          <small class="hint">Tampil di kolom keterangan. Boleh dikosongkan.</small>
        </div>

        <div class="field">
          <label for="sort_order">Urutan Tampil</label>
          <input type="number" id="sort_order" name="sort_order" class="input" min="0" max="999"
                 value="{{ old('sort_order', 0) }}">
          <small class="hint">Angka kecil tampil lebih dulu.</small>
        </div>
      </div>

      <input type="hidden" name="is_active" value="1">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Area
        </button>
      </div>
    </form>
  </div>

  <div class="card" x-data="{ editing: null }">
    <div class="card-head">
      <h3>Daftar Area ({{ $areas->count() }})</h3>
    </div>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th class="ix">#</th>
            <th>Nama Area</th>
            <th>Status</th>
            <th>Keterangan</th>
            <th>Urutan</th>
            <th>Di Website</th>
            <th class="ta-r">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($areas as $i => $area)
            <tr>
              <td class="ix">{{ $i + 1 }}</td>
              <td><b>{{ $area->name }}</b></td>
              <td>
                @if($area->status === \App\Models\CoverageArea::STATUS_TERSEDIA)
                  <span class="badge ok">Tersedia</span>
                @else
                  <span class="badge warn">Segera Hadir</span>
                @endif
              </td>
              <td class="muted">{{ $area->note ?: '' }}</td>
              <td class="mono">{{ $area->sort_order }}</td>
              <td>
                @if($area->is_active)
                  <span class="badge ok">Tampil</span>
                @else
                  <span class="badge">Disembunyikan</span>
                @endif
              </td>
              <td>
                <div class="tbl-actions">
                  <form method="POST" action="{{ route('coverage-areas.toggle', $area->id) }}" style="display:inline-flex">
                    @csrf @method('PATCH')
                    <button type="submit" class="icon-btn" title="{{ $area->is_active ? 'Sembunyikan dari website' : 'Tampilkan di website' }}"
                            style="color:{{ $area->is_active ? 'var(--ok)' : 'var(--text-3)' }}">
                      @if($area->is_active)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                      @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                      @endif
                    </button>
                  </form>

                  <button type="button" class="icon-btn" title="Edit"
                          x-on:click="editing = (editing === {{ $area->id }} ? null : {{ $area->id }})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>

                  <form method="POST" action="{{ route('coverage-areas.destroy', $area->id) }}" style="display:inline-flex"
                        data-confirm="Hapus area &quot;{{ $area->name }}&quot; dari website? Tindakan ini tidak bisa dibatalkan."
                        data-confirm-title="Hapus Area Layanan"
                        data-confirm-action="Hapus"
                        data-confirm-variant="danger">
                    @csrf @method('DELETE')
                    <button type="submit" class="icon-btn t-err" title="Hapus">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>

            <tr x-show="editing === {{ $area->id }}" x-cloak>
              <td colspan="7" style="background:var(--bg-mute);padding:var(--pad-card)">
                <form method="POST" action="{{ route('coverage-areas.update', $area->id) }}">
                  @csrf @method('PUT')
                  <div class="grid-2">
                    <div class="field">
                      <label>Nama Area</label>
                      <input type="text" name="name" class="input" required maxlength="120" value="{{ $area->name }}">
                    </div>
                    <div class="field">
                      <label>Status</label>
                      <select name="status" class="select" required>
                        <option value="tersedia" @selected($area->status === 'tersedia')>Tersedia</option>
                        <option value="segera" @selected($area->status === 'segera')>Segera Hadir</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Keterangan</label>
                      <input type="text" name="note" class="input" maxlength="160" value="{{ $area->note }}">
                    </div>
                    <div class="field">
                      <label>Urutan Tampil</label>
                      <input type="number" name="sort_order" class="input" min="0" max="999" value="{{ $area->sort_order }}">
                    </div>

                    <div class="field span-2">
                      <label>Tampilkan di Website</label>
                      <div style="display:flex;align-items:center;gap:12px;padding:4px 0">
                        <label class="switch">
                          <input type="hidden" name="is_active" value="0">
                          <input type="checkbox" name="is_active" value="1" @checked($area->is_active)>
                          <span class="track"></span>
                        </label>
                        <span style="font-size:13.5px;font-weight:500">Tampil untuk pengunjung</span>
                      </div>
                    </div>
                  </div>

                  <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                    <button type="button" class="btn" x-on:click="editing = null">Batal</button>
                  </div>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td class="empty-cell" colspan="7">
                Belum ada area layanan. Tambahkan lewat form di atas agar muncul di website.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

@endsection

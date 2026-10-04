@extends('layouts.app')

@section('title', 'Notifikasi WA')
@section('page-title', 'Notifikasi WA')

@section('content')

  <header class="page-head">
    <div>
      <h2>Penerima Notifikasi WhatsApp</h2>
      <p>Nomor yang menerima kabar internal dari nomor bisnis
        <span class="mono">{{ $businessWa }}</span>. Isi pesannya diatur di
        <a class="link-strong" href="{{ route('message-templates.index') }}">Template Pesan</a>.
      </p>
    </div>
  </header>

  @if($errors->any())
    <div class="mb-4"><x-admin.alert type="error" :message="$errors->first()"/></div>
  @endif

  <div class="card mb-4">
    <div class="card-head">
      <h3>Tambah Penerima</h3>
    </div>

    <form method="POST" action="{{ route('admin-notifications.store') }}" class="card-pad">
      @csrf
      <div class="grid-2">
        <div class="field">
          <label for="name">Nama</label>
          <input type="text" id="name" name="name" class="input" required maxlength="100"
                 value="{{ old('name') }}" placeholder="contoh: Zein">
          <small class="hint">Hanya untuk penanda di daftar ini.</small>
        </div>

        <div class="field">
          <label for="phone">Nomor WhatsApp</label>
          <input type="text" id="phone" name="phone" class="input" required
                 value="{{ old('phone') }}" placeholder="081234567890">
          <small class="hint">Boleh 08…, +62…, atau 62… (disimpan seragam jadi 62…)</small>
        </div>
      </div>

      <div class="field" style="margin-top:4px">
        <label>Jenis Notifikasi</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;padding:4px 0">
          @foreach($events as $key => $label)
            <label style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:500;cursor:pointer">
              <input type="checkbox" name="events[]" value="{{ $key }}"
                     @checked(in_array($key, old('events', array_keys($events)), true))>
              {{ $label }}
            </label>
          @endforeach
        </div>
        <small class="hint">Nomor hanya menerima jenis yang dicentang.</small>
      </div>

      <input type="hidden" name="is_active" value="1">

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Tambah Penerima
        </button>
      </div>
    </form>
  </div>

  <div class="card" x-data="{ editing: null }">
    <div class="card-head">
      <h3>Daftar Penerima ({{ $recipients->count() }})</h3>
    </div>

    <div class="tbl-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th class="ix">#</th>
            <th>Nama</th>
            <th>Nomor</th>
            <th>Menerima</th>
            <th>Status</th>
            <th class="ta-r">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($recipients as $i => $r)
            <tr>
              <td class="ix">{{ $i + 1 }}</td>
              <td><b>{{ $r->name }}</b></td>
              <td class="mono">{{ $r->phone }}</td>
              <td>
                @php $labels = $r->eventLabels(); @endphp
                @if(count($labels) === count($events))
                  <span class="badge ok">Semua notifikasi</span>
                @elseif($labels)
                  <div style="display:flex;flex-wrap:wrap;gap:5px">
                    @foreach($labels as $label)
                      <span class="badge">{{ $label }}</span>
                    @endforeach
                  </div>
                @else
                  <span class="badge warn">Tidak ada</span>
                @endif
              </td>
              <td>
                @if($r->is_active)
                  <span class="badge ok">Aktif</span>
                @else
                  <span class="badge">Nonaktif</span>
                @endif
              </td>
              <td>
                <div class="tbl-actions">
                  <form method="POST" action="{{ route('admin-notifications.test', $r->id) }}" style="display:inline-flex">
                    @csrf
                    <button type="submit" class="icon-btn" title="Kirim pesan tes ke nomor ini">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                  </form>

                  <form method="POST" action="{{ route('admin-notifications.toggle', $r->id) }}" style="display:inline-flex">
                    @csrf @method('PATCH')
                    <button type="submit" class="icon-btn" title="{{ $r->is_active ? 'Hentikan notifikasi ke nomor ini' : 'Aktifkan notifikasi ke nomor ini' }}"
                            style="color:{{ $r->is_active ? 'var(--ok)' : 'var(--text-3)' }}">
                      @if($r->is_active)
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                      @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                      @endif
                    </button>
                  </form>

                  <button type="button" class="icon-btn" title="Edit"
                          x-on:click="editing = (editing === {{ $r->id }} ? null : {{ $r->id }})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </button>

                  <form method="POST" action="{{ route('admin-notifications.destroy', $r->id) }}" style="display:inline-flex"
                        data-confirm="Hapus &quot;{{ $r->name }}&quot; dari daftar penerima? Nomor ini berhenti menerima notifikasi."
                        data-confirm-title="Hapus Penerima Notifikasi"
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

            <tr x-show="editing === {{ $r->id }}" x-cloak>
              <td colspan="6" style="background:var(--bg-mute);padding:var(--pad-card)">
                <form method="POST" action="{{ route('admin-notifications.update', $r->id) }}">
                  @csrf @method('PUT')
                  <div class="grid-2">
                    <div class="field">
                      <label>Nama</label>
                      <input type="text" name="name" class="input" required maxlength="100" value="{{ $r->name }}">
                    </div>
                    <div class="field">
                      <label>Nomor WhatsApp</label>
                      <input type="text" name="phone" class="input" required value="{{ $r->phone }}">
                    </div>

                    <div class="field span-2">
                      <label>Jenis Notifikasi</label>
                      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;padding:4px 0">
                        @foreach($events as $key => $label)
                          <label style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:500;cursor:pointer">
                            <input type="checkbox" name="events[]" value="{{ $key }}" @checked($r->wants($key))>
                            {{ $label }}
                          </label>
                        @endforeach
                      </div>
                    </div>

                    <div class="field span-2">
                      <label>Status</label>
                      <div style="display:flex;align-items:center;gap:12px;padding:4px 0">
                        <label class="switch">
                          <input type="hidden" name="is_active" value="0">
                          <input type="checkbox" name="is_active" value="1" @checked($r->is_active)>
                          <span class="track"></span>
                        </label>
                        <span style="font-size:13.5px;font-weight:500">Terima notifikasi</span>
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
              <td class="empty-cell" colspan="6">
                Belum ada penerima. Selama daftar ini kosong, notifikasi jatuh ke nomor bawaan di <span class="mono">.env</span>.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

@endsection

@extends('layouts.app')

@section('title', 'Operator')
@section('page-title', 'Operator')

@section('page-class', 'pg-operators-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Operator Panel</h2>
      <p>Kelola akun admin dan operator yang dapat mengakses panel.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('operators.create') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Tambah Operator
      </a>
    </div>
  </header>

  <div class="card">
    <div class="card-head">
      <h3>Semua Akun Panel</h3>
      <div class="ch-actions">
        <input class="input" id="op-search" placeholder="Cari nama / username…" style="max-width:240px" />
      </div>
    </div>
    <div class="tbl-wrap">
      <table class="tbl" id="op-tbl">
        <thead>
          <tr>
            <th class="ix">#</th>
            <th>Nama</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Login Terakhir</th>
            <th class="ta-r">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($users as $i => $u)
            <tr data-name="{{ strtolower($u->name.' '.$u->username.' '.$u->email) }}">
              <td class="ix">{{ $i + 1 }}</td>
              <td>
                <div style="display:flex;align-items:center;gap:10px">
                  <span class="avatar sm">{{ strtoupper(substr($u->name ?? $u->username, 0, 1)) }}</span>
                  <b>{{ $u->name }}</b>
                  @if($u->id === auth()->id())
                    <span class="badge brand no-dot" style="font-size:10px">Anda</span>
                  @endif
                </div>
              </td>
              <td class="mono">{{ $u->username }}</td>
              <td class="muted">{{ $u->email }}</td>
              <td>
                @if($u->role === 'admin')
                  <span class="badge brand">Admin</span>
                @else
                  <span class="badge info">Operator</span>
                @endif
              </td>
              <td>
                @if($u->is_active)
                  <span class="badge ok">Aktif</span>
                @else
                  <span class="badge">Nonaktif</span>
                @endif
              </td>
              <td class="sub-note">
                {{ $u->last_login_at ? $u->last_login_at->locale('id')->diffForHumans() : '' }}
              </td>
              <td>
                <div class="tbl-actions">
                  @if($u->id !== auth()->id())
                    <form method="POST" action="{{ route('operators.toggle', $u->id) }}" style="display:inline-flex">
                      @csrf @method('PATCH')
                      <button type="submit" class="icon-btn" title="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}" style="color:{{ $u->is_active ? 'var(--ok)' : 'var(--text-3)' }}">
                        @if($u->is_active)
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        @else
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        @endif
                      </button>
                    </form>
                  @endif
                  <a href="{{ route('operators.edit', $u->id) }}" class="icon-btn" title="Edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  </a>
                  @if($u->id !== auth()->id())
                    <form method="POST" action="{{ route('operators.destroy', $u->id) }}" style="display:inline-flex"
                          data-confirm="Hapus operator {{ $u->username }}? Tindakan ini tidak bisa dibatalkan."
                          data-confirm-title="Hapus Operator"
                          data-confirm-action="Hapus"
                          data-confirm-variant="danger"
                          data-confirm-password="1"
                          data-confirm-intent="Hapus operator {{ $u->username }}">
                      @csrf @method('DELETE')
                      <button type="submit" class="icon-btn t-err" title="Hapus">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                      </button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td class="empty-cell" colspan="8">
                Belum ada operator.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>


  <script>
    (function () {
      const inp = document.getElementById('op-search');
      const tbl = document.getElementById('op-tbl');
      if (!inp || !tbl) return;
      inp.addEventListener('input', () => {
        const q = inp.value.trim().toLowerCase();
        tbl.querySelectorAll('tbody tr[data-name]').forEach(tr => {
          tr.style.display = !q || tr.dataset.name.includes(q) ? '' : 'none';
        });
      });
    })();
  </script>

@endsection

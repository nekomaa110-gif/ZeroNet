
@php
    $usernamesOnPage = collect($users->items())->pluck('username')->all();
    $latestSessions = collect();
    if (! empty($usernamesOnPage)) {
        $sub = \Illuminate\Support\Facades\DB::table('radacct')
            ->whereIn('username', $usernamesOnPage)
            ->select('username', \Illuminate\Support\Facades\DB::raw('MAX(radacctid) as max_id'))
            ->groupBy('username');

        $latestSessions = \Illuminate\Support\Facades\DB::table('radacct as a')
            ->joinSub($sub, 'b', fn($j) => $j->on('a.username', '=', 'b.username')->on('a.radacctid', '=', 'b.max_id'))
            ->select(
                'a.username',
                'a.framedipaddress',
                'a.callingstationid',
                'a.acctstarttime',
                'a.acctstoptime',
                'a.acctinputoctets',
                'a.acctoutputoctets',
                'a.nasipaddress',
            )
            ->get()
            ->keyBy('username');
    }

    $fmtBytes = function ($n) {
        $n = (int) ($n ?? 0);
        if ($n >= 1073741824) return number_format($n / 1073741824, 2) . ' GB';
        if ($n >= 1048576)    return number_format($n / 1048576, 1) . ' MB';
        if ($n >= 1024)       return number_format($n / 1024, 1) . ' KB';
        return $n . ' B';
    };
@endphp

<div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th class="ix hide-sm">#</th>
        <th>Username</th>
        <th class="hide-sm">Profil / Paket</th>
        <th>Expire</th>
        <th class="hide-sm">Status</th>
        <th class="num"><span class="sr-only">Aksi</span></th>
      </tr>
    </thead>
    <tbody>
      @forelse($users as $user)
        @php
          $expAt      = ! empty($user['expiry_at']) ? \Carbon\Carbon::parse($user['expiry_at']) : null;
          $expired    = $expAt !== null && $expAt->isPast();
          $isDisabled = ! $user['active'];
          $isExpired  = $user['active'] && $expired;
          $isActive   = $user['active'] && ! $expired;

          $initial = mb_strtoupper(mb_substr($user['username'], 0, 1));
          $userKey = strtolower($user['username']);

          $gHasValue = $user['group'] !== '-' && $user['group'] !== '';

          $sisa = ''; $sisaTone = 't-mute';
          if ($expAt !== null) {
              $diffDays = (int) now()->startOfDay()->diffInDays($expAt->copy()->startOfDay(), false);

              if ($diffDays === 0) {
                  $mins = (int) now()->diffInMinutes($expAt, false);
                  if ($mins > 0) {
                      $jam  = intdiv($mins, 60);
                      $sisa = $jam >= 1 ? $jam . ' jam lagi' : $mins . ' mnt lagi';
                  } else {
                      $mins = abs($mins);
                      $jam  = intdiv($mins, 60);
                      $sisa = $jam >= 1 ? $jam . ' jam lalu' : max($mins, 1) . ' mnt lalu';
                  }
                  $sisaTone = $expired ? 't-err' : 't-warn';
              } elseif ($diffDays < 0) {
                  $sisa = abs($diffDays) . ' hr lalu'; $sisaTone = 't-err';
              } elseif ($diffDays <= 3) {
                  $sisa = $diffDays . ' hr lagi';      $sisaTone = 't-warn';
              } elseif ($diffDays <= 14) {
                  $sisa = $diffDays . ' hr lagi';      $sisaTone = 't-ok';
              } else {
                  $sisa = $diffDays . ' hr lagi';      $sisaTone = 't-mute';
              }
          }

          $statusKey = $isDisabled ? 'inactive' : ($isExpired ? 'expired' : 'active');

          $sess = $latestSessions->get($user['username']);
          $sessIp     = $sess?->framedipaddress ?: '';
          $sessMac    = $sess?->callingstationid ?: '';
          $sessRx     = $sess ? $fmtBytes($sess->acctinputoctets)  : '';
          $sessTx     = $sess ? $fmtBytes($sess->acctoutputoctets) : '';
          $sessOnline = $sess && empty($sess->acctstoptime);
          $sessLast   = $sess?->acctstarttime
              ? \Carbon\Carbon::parse($sess->acctstarttime)->format('d M Y H:i')
              : '';

          $invTerbuka = $user['open_invoices'] ?? collect();
          $invPertama = $invTerbuka->first();
          $invLabel   = $invPertama
              ? '#' . $invPertama->id . ' · Rp ' . number_format($invPertama->amount, 0, ',', '.') . ' · ' . $invPertama->customerStatusBadge()[1]
              : '';
        @endphp
        <tr class="user-row is-clickable" tabindex="0" aria-label="Detail {{ $user['username'] }}"
            data-username="{{ $user['username'] }}"
            data-paket="{{ $gHasValue ? $user['group'] : '(Tidak ada)' }}"
            data-expire="{{ $user['expiry'] !== '-' ? $user['expiry'] : 'Tidak ada' }}"
            data-expire-at="{{ $user['expiry_at'] }}"
            data-remain="{{ $sisa }}"
            data-status="{{ $statusKey }}"
            data-ip="{{ $sessIp }}"
            data-mac="{{ $sessMac }}"
            data-rx="{{ $sessRx }}"
            data-tx="{{ $sessTx }}"
            data-last-login="{{ $sessLast }}"
            data-online="{{ $sessOnline ? '1' : '0' }}"
            data-invoice-id="{{ $invPertama?->id }}"
            data-invoice-label="{{ $invLabel }}"
            data-invoice-count="{{ $invTerbuka->count() }}">
          <td class="ix hide-sm">{{ $users->firstItem() + $loop->index }}</td>

          <td>
            <div class="user-cell">
              <div class="avatar sm hide-sm" aria-hidden="true">{{ $initial }}</div>
              <div class="um">
                <b>{{ $user['username'] }}</b>
                <span class="hide-sm">{{ '@'.$userKey }}</span>
                <span class="only-sm">
                  @if ($isDisabled)
                    <span class="badge warn">Nonaktif</span>
                  @elseif ($isExpired)
                    <span class="badge err">Expired</span>
                  @else
                    <span class="badge ok">Aktif</span>
                  @endif
                </span>
              </div>
            </div>
          </td>

          <td class="hide-sm">
            @if ($gHasValue)
              <span class="badge no-dot">{{ $user['group'] }}</span>
            @endif
          </td>

          <td>
            @if ($user['expiry'] !== '-')
              <div class="mono t-strong">{{ $user['expiry'] }}</div>
              <div class="exp-remain {{ $sisaTone }}">{{ $sisa }}</div>
            @endif
          </td>

          <td class="hide-sm">
            @if ($isDisabled)
              <span class="badge warn">Nonaktif</span>
            @elseif ($isExpired)
              <span class="badge err">Expired</span>
            @else
              <span class="badge ok">Aktif</span>
            @endif
          </td>

          <td>
            <div class="tbl-actions">
              <a href="{{ route('user-hotspot.voucher', $user['username']) }}" class="icon-btn" title="Cetak voucher" aria-label="Cetak voucher {{ $user['username'] }}"
                 target="_blank" rel="noopener" onclick="event.stopPropagation()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              </a>

              <a href="{{ route('user-hotspot.edit', $user['username']) }}" class="icon-btn" title="Edit" aria-label="Edit {{ $user['username'] }}"
                 onclick="event.stopPropagation()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>

              @if (! $isActive)
                <form method="POST" action="{{ route('user-hotspot.toggle', $user['username']) }}" class="inline-form" onclick="event.stopPropagation()">
                  @csrf @method('PATCH')
                  <button type="submit" class="icon-btn {{ $user['active'] ? 't-warn' : 't-ok' }}" title="{{ $user['active'] ? 'Nonaktifkan' : 'Aktifkan' }}" aria-label="{{ $user['active'] ? 'Nonaktifkan' : 'Aktifkan' }} {{ $user['username'] }}">
                    @if ($user['active'])
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    @else
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    @endif
                  </button>
                </form>
                <form method="POST" action="{{ route('user-hotspot.destroy', $user['username']) }}" class="inline-form"
                      onclick="event.stopPropagation()"
                      data-confirm="Hapus user {{ $user['username'] }}? Tindakan ini tidak bisa dibatalkan."
                      data-confirm-title="Hapus User Hotspot"
                      data-confirm-action="Hapus"
                      data-confirm-variant="danger"
                      data-confirm-password="1"
                      data-confirm-intent="Hapus user hotspot {{ $user['username'] }}">
                  @csrf @method('DELETE')
                  <button type="submit" class="icon-btn t-err" title="Hapus" aria-label="Hapus {{ $user['username'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
                  </button>
                </form>
              @endif
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="6">
            <div class="empty">
              <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
              @if ($search || $group || $status)
                <div class="empty-title">Tidak ada user yang cocok</div>
                <div>Coba ubah pencarian atau filter.</div>
              @else
                <div class="empty-title">Belum ada user hotspot</div>
                <a href="{{ route('user-hotspot.create') }}">Tambah user pertama</a>
              @endif
            </div>
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

@if ($users->total() > 0)
  <div class="tbl-foot">
    <span>Menampilkan <b>{{ $users->firstItem() ?? 0 }}-{{ $users->lastItem() ?? 0 }}</b> dari <b>{{ $users->total() }}</b> user</span>
    @if ($users->hasPages())
      <div>{{ $users->withQueryString()->links() }}</div>
    @endif
  </div>
@endif

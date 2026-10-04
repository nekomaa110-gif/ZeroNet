<div class="tbl-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th class="ix">#</th>
        <th>Waktu</th>
        <th>User Panel</th>
        <th class="ta-c">Role</th>
        <th>Aksi</th>
        <th>Deskripsi</th>
        <th>IP Address</th>
      </tr>
    </thead>
    <tbody>
      @forelse($logs as $log)
        @php
          $action    = ['label' => \App\Support\ActivityAction::label($log->action), 'tone' => \App\Support\ActivityAction::tone($log->action)];
          $isSystem  = is_null($log->user_id);
          $isCustomer = $log->subject_type === 'customer';
        @endphp
        <tr>
          <td class="ix">{{ $logs->firstItem() + $loop->index }}</td>

          <td class="fs-12 muted nowrap">
            <div>{{ $log->created_at->format('d M Y') }}</div>
            <div class="mono t-mute">{{ $log->created_at->format('H:i:s') }}</div>
          </td>

          <td>
            @if($isCustomer)
              <div class="fw-6">{{ $log->subject_id }}</div>
              <div class="hint">portal pelanggan</div>
            @elseif($isSystem)
              <div style="font-weight:500;color:var(--text-2);font-style:italic">Sistem</div>
              <div class="hint">scheduler</div>
            @else
              <div class="fw-6">{{ $log->user?->name ?? '' }}</div>
              <div class="hint">{{ $log->user?->username ?? '' }}</div>
            @endif
          </td>

          <td class="ta-c">
            @if($isCustomer)
              <span class="badge info">Pelanggan</span>
            @elseif($isSystem)
              <span class="badge">Sistem</span>
            @elseif($log->user?->role === 'admin')
              <span class="badge brand">Admin</span>
            @elseif($log->user?->role === 'operator')
              <span class="badge info">Operator</span>
            @else
              <span class="t-mute"></span>
            @endif
          </td>

          <td>
            <span class="badge {{ $action['tone'] }}">{{ $action['label'] }}</span>
          </td>

          <td style="font-size:12px;color:var(--text-2);max-width:280px">
            {{ $log->description ?: '' }}
          </td>

          <td class="mono hint">
            {{ $log->ip_address ?: '' }}
          </td>
        </tr>
      @empty
        <tr>
          <td class="empty-cell" colspan="7">
            <div class="empty-inner">
              <div class="empty-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
              <div class="t-strong">
                @if($search || $dateFrom || $dateTo) Tidak ada log yang cocok dengan filter @else Belum ada log aktivitas @endif
              </div>
              @if($search || $dateFrom || $dateTo)
                <a class="link-strong" href="{{ route('activity-logs.index') }}">Reset filter →</a>
              @endif
            </div>
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

@if ($logs->hasPages())
  <div class="card-foot">{{ $logs->withQueryString()->links() }}</div>
@endif

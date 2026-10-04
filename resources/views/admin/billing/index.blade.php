@extends('layouts.app')

@section('title', 'Billing')
@section('page-title', 'Billing')

@php
  use App\Models\Invoice;
  $tabs = [
    ''                          => 'Semua',
    Invoice::STATUS_DRAFT       => 'Permintaan Baru',
    Invoice::STATUS_UNPAID      => 'Belum Bayar',
    Invoice::STATUS_PENDING     => 'Menunggu Konfirmasi',
    Invoice::STATUS_PAID        => 'Lunas',
    Invoice::STATUS_CANCELLED   => 'Dibatalkan',
  ];
@endphp

@section('content')
  <header class="page-head">
    <div>
      <h2>Billing Pelanggan</h2>
      <p>Kelola tagihan perpanjangan paket bulanan pelanggan.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('billing.create') }}" class="btn btn-primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Buat tagihan
      </a>
    </div>
  </header>

  <div class="card">
    <form method="GET" action="{{ route('billing.index') }}" id="bill-filter" data-live-target="#billing-results">
      <div class="tabs card-tabs" role="tablist" aria-label="Filter status tagihan">
        @foreach ($tabs as $key => $label)
          @php
            $count = $key === '' ? array_sum($counts) : ($counts[$key] ?? 0);
            $active = (string) $status === (string) $key;
            $needsAction = in_array($key, [Invoice::STATUS_DRAFT, Invoice::STATUS_PENDING], true) && $count > 0;
          @endphp
          <button type="button" role="tab" data-status-pick="{{ $key }}" class="{{ $active ? 'active' : '' }}" aria-selected="{{ $active ? 'true' : 'false' }}">
            {{ $label }} <span class="count {{ $needsAction ? 'is-warn' : '' }}">{{ $count }}</span>
          </button>
        @endforeach
      </div>

      <input type="hidden" name="status" id="bill-status" value="{{ $status }}" data-live-submit>

      <div class="toolbar">
        <div class="input-group tb-search">
          <svg class="ig-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="search" name="search" value="{{ $search }}" placeholder="Cari username" aria-label="Cari tagihan per username"
                 data-live-search class="input">
        </div>
      </div>
    </form>

    <div id="billing-results" data-live-results>
      @include('admin.billing._results')
    </div>
  </div>

  <script>
    (function () {
      var hidden = document.getElementById('bill-status');
      var tabs   = document.querySelectorAll('#bill-filter [data-status-pick]');
      if (!hidden || !tabs.length) return;

      function sync() {
        tabs.forEach(function (b) {
          var on = b.getAttribute('data-status-pick') === hidden.value;
          b.classList.toggle('active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
      }

      tabs.forEach(function (b) {
        b.addEventListener('click', function () {
          var v = b.getAttribute('data-status-pick');
          if (hidden.value === v) return;
          hidden.value = v;
          sync();
          hidden.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });

      window.addEventListener('popstate', function () { setTimeout(sync, 0); });
    })();
  </script>

@endsection

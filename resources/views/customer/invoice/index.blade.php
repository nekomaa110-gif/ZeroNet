@extends('customer.layout')
@section('title', 'Riwayat Tagihan · ZeroNet')
@section('content-class', '')

@section('content')
  <a class="backlink" href="{{ route('customer.dashboard') }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    Kembali
  </a>

  <div class="page-title">
    <h1>Riwayat tagihan</h1>
    <p>{{ $invoices->total() }} tagihan</p>
  </div>

  @if ($invoices->count())
    <div class="card">
      @foreach ($invoices as $inv)
        @include('customer.invoice._item', ['inv' => $inv])
      @endforeach
    </div>

    @if ($invoices->hasPages())
      <nav class="pager" aria-label="Halaman riwayat">
        @if ($invoices->previousPageUrl())
          <a class="btn btn-secondary btn-block" href="{{ $invoices->previousPageUrl() }}">Sebelumnya</a>
        @endif
        @if ($invoices->nextPageUrl())
          <a class="btn btn-secondary btn-block" href="{{ $invoices->nextPageUrl() }}">Berikutnya</a>
        @endif
      </nav>
    @endif
  @else
    <div class="card center">
      <p class="muted">Belum ada tagihan.</p>
    </div>
  @endif
@endsection

@section('fab')
  @include('site.partials.wa-button', ['href' => 'https://wa.me/'.$businessWa])
@endsection

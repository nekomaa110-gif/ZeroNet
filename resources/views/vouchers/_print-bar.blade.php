  <div class="bar">
    <div>
      <h1>{{ $batch->code }}</h1>
      <div class="sub">
        {{ $vouchers->count() }} kartu · {{ $batch->profile }} · {{ $batch->router_label }}
        @if ($batch->validity) · masa aktif {{ $batch->validity }} @endif
      </div>
    </div>

    <span class="spacer"></span>

    <select id="tplPick" title="Template cetak">
      @foreach ($pilihan as $key => $label)
        <option value="{{ $key }}" @selected($key === $tplKey)>{{ $label }}</option>
      @endforeach
    </select>

    <a href="{{ route('vouchers.show', $batch) }}">Kembali</a>
    <button type="button" class="primary" id="printBtn">Cetak / Simpan PDF</button>
  </div>

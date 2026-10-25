<div class="card mb-4 verdict verdict-warn">
  <div class="card-head">
    <h3>{{ match ($ujung) { 'dari' => 'Kode awal', 'sampai' => 'Kode akhir', default => 'Kode ini' } }} ada di {{ count($pilihan) }} kartu</h3>
  </div>
  <div class="card-pad muted fs-13" style="padding-top:0">Pilih kartu yang sesuai dengan kartu fisik.</div>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Batch</th><th>Router</th><th>No</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($pilihan as $o)
          <tr>
            <td class="mono">{{ $o['batch_code'] ?? '-' }}</td>
            <td>{{ $o['router_name'] ?? '-' }}</td>
            <td class="mono">{{ $o['nomor'] ?? '-' }}</td>
            <td>{{ $o['sumber'] === 'terhapus' ? 'Dihapus (' . $o['status_label'] . ')' : $o['status_label'] }}</td>
            <td>
              <button type="button" class="btn btn-sm"
                      data-pilih-sumber="{{ $o['sumber'] }}" data-pilih-id="{{ $o['id'] }}"
                      data-pilih-batch="{{ $o['batch_code'] }}" data-pilih-router="{{ $o['router_slug'] }}"
                      data-ujung="{{ $ujung ?? '' }}">Pilih</button>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

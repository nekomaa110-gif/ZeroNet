<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <style>
    body { color: #000; background-color: #fff; font-size: 14px; font-family: 'Helvetica', arial, sans-serif; margin: 8px; }
    .kisi { display: grid; grid-template-columns: repeat({{ max(1, $perRow) }}, 1fr); gap: 3mm; min-width: 200mm; }
  </style>
  <style>{!! $gaya !!}</style>
</head>
<body>
  @if ($perRow > 0)
    <div class="kisi">
      @foreach ($kartu as $k)
        <div>{!! $k !!}</div>
      @endforeach
    </div>
  @else
    @foreach ($kartu as $k){!! $k !!}@endforeach
  @endif
</body>
</html>

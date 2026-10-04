<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="{{ asset('assets/shell.js') }}?v={{ @filemtime(public_path('assets/shell.js')) ?: 1 }}"></script>
@if (! empty($withAlpine))
  @vite(['resources/css/app.css', 'resources/js/app.js'])
@endif
<link rel="stylesheet" href="{{ asset('assets/zn-tokens.css') }}?v={{ @filemtime(public_path('assets/zn-tokens.css')) ?: 1 }}" />
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ @filemtime(public_path('assets/app.css')) ?: 1 }}" />
<link rel="stylesheet" href="{{ asset('assets/auth.css') }}?v={{ @filemtime(public_path('assets/auth.css')) ?: 1 }}" />

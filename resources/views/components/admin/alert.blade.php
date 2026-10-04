@props(['type' => 'success', 'message' => ''])

@php
    $tone = [
        'success' => 'ok',
        'error'   => 'err',
        'warning' => 'warn',
        'info'    => 'info',
    ][$type] ?? 'info';
@endphp

<div x-data="{ show: true }" x-show="show" role="{{ $tone === 'err' ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => 'alert tone-'.$tone]) }}>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        @if($type === 'success')
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
        @elseif($type === 'error')
            <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        @elseif($type === 'warning')
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        @else
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
        @endif
    </svg>
    <span class="alert-text">{{ $message }}</span>
    <button @click="show = false" type="button" class="alert-close" aria-label="Tutup pesan">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
</div>

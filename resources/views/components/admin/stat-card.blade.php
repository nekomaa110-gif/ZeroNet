@props([
    'label'  => '',
    'value'  => '0',
    'icon'   => 'default',
    'color'  => 'blue',
    'trend'  => null,
])

@php
    $tone = [
        'blue'   => 'tone-info',
        'green'  => 'tone-ok',
        'yellow' => 'tone-warn',
        'red'    => 'tone-rose',
        'purple' => '',
    ][$color] ?? 'tone-info';

    $icons = [
        'users'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M22 21v-2a4 4 0 0 0-3-3.87"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'online'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12.55a11 11 0 0 1 14 0"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 8.82a15 15 0 0 1 20 0"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.5 16.43a6 6 0 0 1 7 0"/><circle cx="12" cy="20" r="1.2" fill="currentColor"/>',
        'voucher' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 9.5V7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v2.5a2.5 2.5 0 0 0 0 5V17a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-2.5a2.5 2.5 0 0 0 0-5z"/>',
        'active'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>',
        'default' => '<circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v5l3 2"/>',
    ];
    $iconPath = $icons[$icon] ?? $icons['default'];
@endphp

<div {{ $attributes->merge(['class' => 'stat '.$tone]) }}>
    <div class="stat-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $iconPath !!}</svg>
    </div>
    <div class="stat-label">{{ $label }}</div>
    <div class="stat-value">{{ $value }}</div>
    @if($trend)
        <div class="stat-foot">{{ $trend }}</div>
    @endif
</div>

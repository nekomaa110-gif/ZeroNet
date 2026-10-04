@props(['color' => 'gray', 'dot' => false])

@php
    $map = [
        'green'  => 'ok',
        'red'    => 'err',
        'yellow' => 'warn',
        'blue'   => 'info',
        'purple' => 'brand',
        'gray'   => '',
    ];
    $cls = $map[$color] ?? '';
@endphp

<span {{ $attributes->merge(['class' => trim('badge '.$cls.($dot ? '' : ' no-dot'))]) }}>
    {{ $slot }}
</span>

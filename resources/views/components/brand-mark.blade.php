{{--
    Novarr mark — three book spines forming an N with an amber bookmark ribbon
    on the right spine. 32×32 grid, flat (the old rounded tile is retired).

    Variants:
        <x-brand-mark />                      gradient (#6470FF → #9B6BFF)
        <x-brand-mark variant="mono" />       single currentColor, ribbon at 55%
--}}
@props([
    'variant' => 'gradient',
    'size' => 28,
])

@php
    // Each gradient instance needs its own id so multiple marks on one page
    // don't collide.
    $gradientId = 'novarr-mark-' . \Illuminate\Support\Str::random(6);
@endphp

@if($variant === 'mono')
    <svg {{ $attributes->merge(['class' => 'brand-mark', 'aria-hidden' => 'true']) }}
         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"
         width="{{ $size }}" height="{{ $size }}" fill="currentColor" focusable="false">
        <path d="M6 5h5v22H6z"/>
        <path d="M11 5h5l10 22h-5z"/>
        <path d="M21 13h5v14h-5z"/>
        <path d="M21 5h5v8l-2.5-2.2L21 13z" opacity="0.55"/>
    </svg>
@else
    <svg {{ $attributes->merge(['class' => 'brand-mark', 'aria-hidden' => 'true']) }}
         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"
         width="{{ $size }}" height="{{ $size }}" focusable="false">
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#6470FF"/>
                <stop offset="100%" stop-color="#9B6BFF"/>
            </linearGradient>
        </defs>
        <path d="M6 5h5v22H6z" fill="url(#{{ $gradientId }})"/>
        <path d="M11 5h5l10 22h-5z" fill="url(#{{ $gradientId }})"/>
        <path d="M21 13h5v14h-5z" fill="url(#{{ $gradientId }})"/>
        <path d="M21 5h5v8l-2.5-2.2L21 13z" fill="#F0B429"/>
    </svg>
@endif

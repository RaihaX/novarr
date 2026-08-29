{{--
    Lucide icon (https://lucide.dev, ISC licence).

    The brand system uses a small, fixed set of icons; they are inlined here so
    no icon font or runtime request is needed and `currentColor` picks up the
    surrounding text colour.

    Usage:
        <x-icon name="search" />
        <x-icon name="triangle-alert" :size="16" class="attention-icon" />
        <x-icon name="chevron-right" :size="14" :stroke="1.5" />
--}}
@props([
    'name',
    'size' => 16,
    'stroke' => 2,
])

@php
    // 24×24 Lucide geometry, drawn with stroke=currentColor.
    $paths = [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'book-open' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
    ];

    $body = $paths[$name] ?? null;
@endphp

@if($body)
    <svg {{ $attributes->merge(['class' => 'icon', 'aria-hidden' => 'true']) }}
         xmlns="http://www.w3.org/2000/svg"
         width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="{{ $stroke }}"
         stroke-linecap="round" stroke-linejoin="round"
         focusable="false">{!! $body !!}</svg>
@endif

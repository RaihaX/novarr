{{--
    Novarr mark — "Serial": four flat horizontal bars on a 32×32 grid, read
    as lines of text / a chapter list. Three indigo bars stepping down in
    length, the last (shortest) bar in amber. Radius 0, no gradient.

    Variants:
        <x-brand-mark />                      colour (indigo #6470FF + amber #F0B429)
        <x-brand-mark variant="mono" />       single currentColor, last bar at 55%
        <x-brand-mark :mono="true" />         same as variant="mono"
        <x-brand-mark variant="favicon" />    heavier three-bar cut for tiny sizes

    Below 20px the three-bar favicon cut is used automatically (in colour or
    mono) — the four-bar mark's 4-unit gaps close up at that size.

    Props: size (px, default 28), variant, mono, title. Pass a title to expose
    the mark as an image (role="img" + <title>); otherwise it is decorative.
    "gradient" / "color" are accepted as legacy aliases of the colour variant.
--}}
@props([
    'variant' => 'color',
    'size' => 28,
    'mono' => false,
    'title' => null,
])

@php
    $isMono = $mono || $variant === 'mono';
    $isFaviconCut = $variant === 'favicon' || (is_numeric($size) && $size < 20);

    $indigo = '#6470FF';
    $amber = '#F0B429';

    // [x, y, width, height] on the 32-grid; the last bar is the amber one.
    $bars = $isFaviconCut
        ? [[4, 4, 24, 6], [4, 13, 24, 6], [4, 22, 11, 6]]
        : [[5, 5, 22, 4], [5, 11.5, 22, 4], [5, 18, 15, 4], [5, 24.5, 8, 4]];
    $last = count($bars) - 1;

    $a11y = $title !== null && $title !== ''
        ? ['role' => 'img']
        : ['aria-hidden' => 'true'];
@endphp

<svg {{ $attributes->merge(['class' => 'brand-mark'] + $a11y) }}
     xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"
     width="{{ $size }}" height="{{ $size }}" focusable="false"
     @if($isMono) fill="currentColor" @endif>
    @if($title !== null && $title !== '')<title>{{ $title }}</title>@endif
    @foreach($bars as $i => [$x, $y, $w, $h])
        @if($isMono)
            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}"@if($i === $last) opacity="0.55"@endif/>
        @else
            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}" fill="{{ $i === $last ? $amber : $indigo }}"/>
        @endif
    @endforeach
</svg>

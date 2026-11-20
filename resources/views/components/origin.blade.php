{{--
    Origin country badge: "JP" / "KR" / "CN" / "EN" from Novel::originCountry(),
    with Novel::originCountryLabel() ("Korean manhwa", "English original") as
    its tooltip and accessible name. Renders nothing when the origin is not
    known well enough (see originCountry()).

    Usage:
        <x-origin :novel="$novel" />
        <x-origin :novel="$novel" class="ms-1" />
--}}
@props(['novel'])

@php
    $code = $novel instanceof \App\Novel ? $novel->originCountry() : null;
    $label = $code ? $novel->originCountryLabel() : null;
@endphp

@if($code)
    <span {{ $attributes->merge(['class' => 'badge badge-origin']) }} data-origin="{{ $code }}" role="img" aria-label="Origin: {{ $label }}" title="{{ $label }}">{{ $code }}</span>
@endif

{{--
    Status badge in the brand's status triad, driven by App\Enums\NovelState.

    Usage:
        <x-status state="paused" />
        <x-status :state="\App\Enums\NovelState::forNovel($novel)" id="novelStatusBadge" />
        <x-status state="attention">Author's note</x-status>      (custom label)
        <x-status state="downloaded" as="text">1,204</x-status>  (coloured text only)

    Props:
        state  NovelState|string  enum case, value ('queued') or case name ('Queued')
        as     'badge' (default) | 'text' | 'panel'
        label  optional label override (the slot wins over this)
--}}
@props([
    'state',
    'as' => 'badge',
    'label' => null,
])

@php
    $resolved = \App\Enums\NovelState::resolve($state) ?? \App\Enums\NovelState::Paused;
    $classes = match ($as) {
        'text' => $resolved->textClass(),
        'panel' => $resolved->panelClass(),
        default => 'badge ' . $resolved->badgeClass(),
    };
    $text = trim((string) $slot) !== '' ? $slot : ($label ?? $resolved->label());
@endphp

<span {{ $attributes->merge(['class' => $classes, 'data-state' => $resolved->value]) }}>{{ $text }}</span>

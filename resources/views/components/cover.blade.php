{{--
    Novel cover, 2:3. The real cover image when there is one; otherwise the
    typographic cover — the same look App\Services\DefaultCoverGenerator draws
    for ePubs (dark ground, indigo→violet rule off the top edge, Literata 600
    title flush left, author micro-label, mono chapter count).

    Usage:
        [x-cover :novel="$novel"]
        [x-cover :novel="$novel" size="sm" :progress="44" :flag="NovelState::Attention"]
        [x-cover :novel="['name' => …, 'author' => …, 'cover' => $url, 'chapters' => 323]" decorative]

    Props:
        novel       Novel model, array or object: name, author, and either a
                    `file` relation (Novel) or a `cover` URL; chapter count
                    from `chapters` / `no_of_chapters`.
        size        sm (72×108) | md (180×270, default) | lg (240×360) — the
                    intrinsic width/height of the <img>; the box itself is
                    fluid and keeps 2:3, so grids size it.
        progress    reading progress 0–100: a 3px amber edge along the bottom
                    (0 → no edge at all).
        flag        exception corner flag, NovelState|string|null — only
                    Attention (amber), Failed ("Failing", red) or Paused
                    (muted). Anything else is ignored: flags are for exceptions.
        src         cover URL override.
        chapters    chapter-count override for the typographic cover.
        chip        optional micro-label, bottom-right just above the progress
                    edge — e.g. Novel::originChip() ("TRANSLATED · KO");
                    null/empty renders nothing.
        decorative  true when the novel's title is printed next to the cover
                    (tiles): the image gets alt="" so it isn't read twice.
--}}
@props([
    'novel',
    'size' => 'md',
    'progress' => 0,
    'flag' => null,
    'src' => null,
    'chapters' => null,
    'chip' => null,
    'decorative' => false,
])

@php
    $get = fn(string $key) => is_array($novel) ? ($novel[$key] ?? null) : ($novel->{$key} ?? null);

    $name = trim((string) $get('name')) ?: 'Untitled';
    $author = trim((string) $get('author'));

    // Cover URL: explicit override, an array/object `cover`, or the Novel's
    // file relation (only when it is already loaded — never lazy-load here).
    $url = $src ?: $get('cover');
    if (!$url && $novel instanceof \Illuminate\Database\Eloquent\Model && $novel->relationLoaded('file') && $novel->file) {
        $url = \Illuminate\Support\Facades\Storage::url($novel->file->file_path);
    }

    $count = $chapters ?? $get('chapters') ?? $get('no_of_chapters');
    $count = is_numeric($count) && (int) $count > 0 ? (int) $count : null;

    [$w, $h] = match ($size) {
        'sm' => [72, 108],
        'lg' => [240, 360],
        default => [180, 270],
    };
    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';

    // Title steps by length, as the ePub cover does (≤18 / ≤44 / longer);
    // the longest step is cut at a word boundary.
    $len = mb_strlen($name);
    $step = $len <= 18 ? 'l' : ($len <= 44 ? 'm' : 's');
    $title = $len > 90 ? \Illuminate\Support\Str::limit($name, 88, '…') : $name;

    $progress = max(0, min(100, (int) $progress));

    // Exceptions only: Attention / Failing / Paused.
    $flagState = \App\Enums\NovelState::resolve($flag);
    $flagLabel = match ($flagState) {
        \App\Enums\NovelState::Attention => 'Attention',
        \App\Enums\NovelState::Failed => 'Failing',
        \App\Enums\NovelState::Paused => 'Paused',
        default => null,
    };
@endphp

<div {{ $attributes->merge(['class' => "cover cover-{$size}" . ($url ? ' has-image' : '') . (filled($chip) ? ' has-chip' : '')]) }}>
    {{-- The typographic cover is always drawn: it is what shows while an
         image loads and what remains if the image fails. --}}
    <div class="cover-type" aria-hidden="true">
        <span class="cover-rule"></span>
        <span class="cover-title cover-title-{{ $step }}">{{ $title }}</span>
        @if($size !== 'sm' && ($author !== '' || $count))
            <span class="cover-foot">
                <span class="cover-author">{{ $author }}</span>
                @if($count)
                    <span class="cover-count">{{ number_format($count) }} CH</span>
                @endif
            </span>
        @endif
    </div>
    @if($url)
        <img src="{{ $url }}" alt="{{ $decorative ? '' : 'Cover of ' . $name }}" width="{{ $w }}" height="{{ $h }}"
             loading="lazy" decoding="async" onerror="this.parentNode.classList.remove('has-image');this.remove()">
    @elseif(!$decorative)
        <span class="visually-hidden">Cover of {{ $name }}</span>
    @endif
    @if($flagLabel)
        <x-status :state="$flagState" :label="$flagLabel" class="cover-flag" />
    @endif
    @if(filled($chip))
        <span class="cover-chip">{{ $chip }}</span>
    @endif
    @if($progress > 0)
        <span class="cover-progress" aria-hidden="true"><span style="width: {{ $progress }}%"></span></span>
    @endif
</div>

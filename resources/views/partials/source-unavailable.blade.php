{{--
    Chapters of $novel the source lists but does not serve, parked by the
    scraper until the next re-check (App\Scraping\SourceUnavailable).
    Renders nothing when the novel has none. Pass $parkedCounts (from
    SourceUnavailable::parkedCounts()) when rendering many novels, to avoid
    one query per novel.
--}}
@php
    $unavailable = null;
    if ((int) ($novel->source_fail_streak ?? 0) > 0) {
        $unavailable = isset($parkedCounts)
            ? ($parkedCounts[$novel->id] ?? null)
            : (\App\Scraping\SourceUnavailable::parkedCounts([(int) $novel->id])[(int) $novel->id] ?? null);
    }
@endphp
@if($unavailable && $unavailable['count'] > 0)
    <div class="alert alert-warning source-unavailable" role="status">
        {{ number_format($unavailable['count']) }} {{ $unavailable['count'] === 1 ? 'chapter is' : 'chapters are' }}
        listed on the source but not available there ({{ \App\Scraping\SourceUnavailable::reasonWords($unavailable['reasons']) }}).
        @if($novel->source_recheck_at)
            Novarr checks again on {{ \Carbon\Carbon::parse($novel->source_recheck_at)->format('j M Y \a\t H:i') }}.
        @endif
    </div>
@endif

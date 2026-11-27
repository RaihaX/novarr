{{--
    App\Mail\NewChapters — the daily summary, in the shared frame
    (emails.layout): the date in the header, then its own rows (continue
    reading, the stats strip, the sections), then the footer links.
--}}
@extends('emails.layout')

@php
    /** @var array $v  normalised view model — see App\Mail\NewChapters::viewData() */
    $tz = config('app.timezone');
    $continue = $v['continue'];
    $stats = $v['stats'];
    $novels = $v['novels'];
    $manga = $v['manga'];
    // The "New chapters" figure counts novel chapters; manga has its own.
    $novelChapters = max(0, (int) $stats['new_chapters'] - (int) $stats['manga_chapters']);
    $completed = $v['completed'];
    $attention = $v['attention'];
    $stalled = $v['stalled'] ?? [];
    $links = $v['links'];
    // A reader's summary (NewChapters::viewData()): their series only, no
    // admin figures, sections or links. Directives that wrap the owner's
    // markup sit at the left margin, so the owner's email keeps its bytes.
    $reader = $v['reader'] ?? null;

    // Brand tokens (design_handoff_novarr_brand). No web fonts, no classes in
    // the markup beyond the nv-* dark-mode hooks — every value is inlined.
    $sans = \App\Mail\MailTheme::SANS;
    $serif = \App\Mail\MailTheme::SERIF;  // stands in for Literata
    $mono = \App\Mail\MailTheme::MONO;

    // Status triad — full-value text, 12% fill, 35% border. Outlook has no
    // rgba(), so the alphas are pre-composited against the surface they sit on.
    $chip = fn (string $text, string $fill, string $border) =>
        'display: inline-block; font-family: ' . $mono . '; font-size: 10px; font-weight: 600; letter-spacing: 0.1em; '
        . 'text-transform: uppercase; color: ' . $text . '; background-color: ' . $fill . '; border: 1px solid ' . $border . '; '
        . 'padding: 2px 6px; white-space: nowrap; line-height: 14px; vertical-align: 2px;';
    $chipNote = $chip('#F0B429', '#302C21', '#625023');        // warning over #161A20
    $chipCount = $chip('#8EA2FF', '#1F243B', '#31386E');       // accent over #161A20

    $label = "font-family: {$sans}; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase;";
    $h2 = "font-family: {$sans}; font-size: 18px; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0;";
    $spacer = fn (int $h) => \App\Mail\MailTheme::spacer($h);

    $preheader = collect([
        $stats['new_chapters'] ? number_format($stats['new_chapters']) . ' new chapters' : null,
        $stats['manga_updated'] ? number_format($stats['manga_updated']) . ' manga updated' : null,
        $stats['completed'] ? $stats['completed'] . ' completed' : null,
        $stats['attention'] ? $stats['attention'] . ' need attention' : null,
        !empty($stats['stalled']) ? $stats['stalled'] . ' ' . ((int) $stats['stalled'] === 1 ? 'source' : 'sources') . ' not downloading' : null,
        $continue ? 'Continue ' . $continue['name'] : null,
    ])->filter()->implode(' · ');

    // Stat tiles: 3 + 2 (3 + 3 with manga) so they survive a 320px viewport.
    $tile = function (string $name, $value, string $tone) {
        return ['name' => $name, 'value' => $value, 'tone' => $tone];
    };
    if ($reader) {
        // A reader: their new chapters, manga and completed series, one row.
        $tiles = [array_values(array_filter([
            $tile('New chapters', $stats['new_chapters'], '#E8EBF0'),
            (int) $stats['manga_updated'] > 0 ? $tile('Manga updated', $stats['manga_updated'], '#E8EBF0') : null,
            $tile('Completed', $stats['completed'], '#3FB950'),
        ]))];
    } else {
        $tiles = [
            [
                $tile('New chapters', $stats['new_chapters'], '#E8EBF0'),
                $tile('Novels updated', $stats['novels_updated'], '#E8EBF0'),
                $tile('Completed', $stats['completed'], '#3FB950'),
            ],
            [
                $tile('Queued', $stats['queued'], '#4CC4D1'),
                $tile('Needs attention', $stats['attention'], '#F0B429'),
            ],
        ];
        if ((int) $stats['manga_updated'] > 0) {
            array_unshift($tiles[1], $tile('Manga updated', $stats['manga_updated'], '#E8EBF0'));
        }
    }

    // The shared frame (emails.layout).
    $mailTitle = 'Novarr – Daily Summary';
    $headerAside = strtoupper($v['date']->format('D d M Y'));
    $footerLinks = $reader
        ? ['Open Novarr' => $links['home'], 'Account' => $links['account']]
        : ['Open Novarr' => $links['home'], 'Activity' => $links['activity'], 'Settings' => $links['settings']];
@endphp

@section('rows')
                {{-- ── Continue reading: the personal, actionable part, first ── --}}
                @if ($continue)
                    <tr>
                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 20px 20px 22px; border-bottom: 1px solid #262D38;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="{{ $label }} color: #F0B429; padding-bottom: 8px;">Continue reading</td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom: 6px;">
                                        <a class="nv-text" href="{{ $continue['novel_url'] }}" style="font-family: {{ $serif }}; font-size: 21px; line-height: 1.3; font-weight: 700; color: #E8EBF0; text-decoration: none;">{{ $continue['name'] }}</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="nv-muted" style="font-family: {{ $mono }}; font-size: 12px; line-height: 1.6; letter-spacing: 0.04em; color: #8B95A5;">
                                        CHAPTER {{ number_format($continue['index']) }} OF {{ number_format($continue['total']) }} · {{ $continue['chapter_percent'] }}% OF CHAPTER
                                    </td>
                                </tr>
                                @if (!empty($continue['chapter_label']) && !preg_match('/^\s*chapter\s*[\d.,]+\s*$/i', $continue['chapter_label']))
                                    <tr>
                                        <td style="padding-top: 2px; font-family: {{ $sans }}; font-size: 13px; line-height: 1.5; color: #D8DDE6;">{{ \Illuminate\Support\Str::limit($continue['chapter_label'], 90) }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding-top: 16px;">
                                        {{-- Bulletproof primary button: a filled cell, not an image. --}}
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: separate;">
                                            <tr>
                                                <td align="center" bgcolor="#4F5CE6" class="nv-button-cell" style="background-color: #4F5CE6; border-radius: 4px;">
                                                    <a href="{{ $continue['url'] ?? route('continue') }}" style="display: inline-block; padding: 10px 22px; font-family: {{ $sans }}; font-size: 14px; font-weight: 600; line-height: 20px; color: #FFFFFF; text-decoration: none; border-radius: 4px;">Resume &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- ── Stats strip: 3 + 2 (or 3 + 3) tiles, mono figures, muted when zero ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 20px 20px 4px;">
                        @if ($v['since'])
                            <p class="nv-muted" style="margin: 0 0 10px; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #6B7684;">
                                SINCE {{ strtoupper(\Illuminate\Support\Carbon::parse($v['since'])->timezone($tz)->format('D d M, g:i A')) }}
                            </p>
                        @endif
                        @foreach ($tiles as $row)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; table-layout: fixed;">
                                <tr>
                                    @foreach ($row as $t)
                                        @php
                                            $zero = $t['value'] === null || (int) $t['value'] === 0;
                                            $figure = $t['value'] === null ? '—' : number_format((int) $t['value']);
                                        @endphp
                                        <td valign="top" bgcolor="#161A20" class="nv-surface" style="vertical-align: top; background-color: #161A20; border: 1px solid #262D38; padding: 10px 12px;">
                                            <div style="font-family: {{ $mono }}; font-size: 22px; line-height: 26px; font-weight: 600; color: {{ $zero ? '#5B6472' : $t['tone'] }};">{{ $figure }}</div>
                                            <div style="{{ $label }} line-height: 14px; padding-top: 4px; color: {{ $zero ? '#5B6472' : '#8B95A5' }};">{{ $t['name'] }}</div>
                                        </td>
                                        @unless ($loop->last)
                                            <td width="8" style="width: 8px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                        @endunless
                                    @endforeach
                                </tr>
                            </table>
                            @unless ($loop->last)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{!! $spacer(8) !!}</table>
                            @endunless
                        @endforeach
                    </td>
                </tr>

                {{-- ── Body sections ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 8px 20px 24px;">

                        {{-- New chapters: one row per novel --}}
                        @if (count($novels) > 0)
                            @include('emails.newchapters_section', [
                                'title' => 'New chapters',
                                'figure' => number_format($novelChapters) . ' IN ' . number_format($stats['novels_updated']) . ' ' . ((int) $stats['novels_updated'] === 1 ? 'NOVEL' : 'NOVELS'),
                                'rows' => $novels,
                                'more' => $v['more_novels'],
                                'one' => 'novel',
                                'many' => 'novels',
                            ])
                        @endif

                        {{-- Manga: the same rows, in their own section --}}
                        @if (count($manga) > 0)
                            @include('emails.newchapters_section', [
                                'title' => 'Manga',
                                'gap' => count($novels) > 0 ? 28 : 20,
                                'figure' => number_format($stats['manga_chapters']) . ' IN ' . number_format($stats['manga_updated']) . ' MANGA',
                                'rows' => $manga,
                                'more' => $v['more_manga'],
                                'one' => 'manga',
                                'many' => 'manga',
                            ])
                        @endif

                        {{-- Completed --}}
                        @if (count($completed) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                {!! $spacer(28) !!}
                                <tr><td class="nv-text" style="{{ $h2 }} padding-bottom: 12px;">Completed</td></tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#15261D" style="background-color: #15261D; border: 1px solid #204C2A; border-collapse: separate;">
                                @foreach ($completed as $novel)
                                    <tr>
                                        <td bgcolor="#15261D" style="background-color: #15261D; padding: 12px 16px; {{ $loop->first ? '' : 'border-top: 1px solid #204C2A;' }}">
                                            <div style="font-family: {{ $serif }}; font-size: 16px; line-height: 1.35; font-weight: 700;">
                                                @if (!empty($novel['url']))
                                                    <a class="nv-text" href="{{ $novel['url'] }}" style="color: #E8EBF0; text-decoration: none;">{{ $novel['name'] }}</a>
                                                @else
                                                    <span class="nv-text" style="color: #E8EBF0;">{{ $novel['name'] }}</span>
                                                @endif
                                            </div>
                                            <div style="padding-top: 4px; font-family: {{ $mono }}; font-size: 11px; line-height: 1.6; letter-spacing: 0.06em; color: #3FB950;">
                                                COMPLETE @if (!empty($novel['completed_at']))· {{ strtoupper(\Illuminate\Support\Carbon::parse($novel['completed_at'])->timezone($tz)->format('d M Y')) }}@endif
                                            </div>
                                            @if (!empty($novel['epub_url']))
                                                <div style="padding-top: 6px; font-family: {{ $sans }}; font-size: 13px; line-height: 1.5; color: #8B95A5;">
                                                    <a class="nv-link" href="{{ $novel['epub_url'] }}" style="color: #8EA2FF; font-weight: 600; text-decoration: none;">Download ePub &darr;</a>@if (!empty($novel['kindle']))<span class="nv-muted" style="color: #8B95A5;"> &nbsp;·&nbsp; Sent to Kindle</span>@endif
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        {{-- Sources not downloading (DownloadLanes::stalled()): same panel as Needs attention. Never for a reader. --}}
                        @if (!$reader && count($stalled) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                {!! $spacer(28) !!}
                                <tr>
                                    <td style="padding-bottom: 12px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td class="nv-text" style="{{ $h2 }}">Sources not downloading</td>
                                                <td align="right" style="font-family: {{ $sans }}; font-size: 13px; font-weight: 600; white-space: nowrap;">
                                                    <a class="nv-link" href="{{ $links['activity'] }}" style="color: #8EA2FF; text-decoration: none;">View activity &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; border: 1px solid #262D38; border-left: 2px solid #F0B429; border-collapse: separate;">
                                @foreach ($stalled as $lane)
                                    <tr data-stalled-lane="{{ $lane['lane'] }}">
                                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 12px 16px; {{ $loop->first ? '' : 'border-top: 1px solid #262D38;' }}">
                                            <div style="font-family: {{ $sans }}; font-size: 14px; line-height: 1.4;"><span class="nv-text" style="color: #E8EBF0; font-weight: 600;">{{ $lane['label'] }}</span><span style="color: #D8DDE6;"> — {{ number_format($lane['queued']) }} {{ (int) $lane['queued'] === 1 ? 'chapter' : 'chapters' }} waiting, nothing downloaded for {{ number_format($lane['hours']) }} {{ (int) $lane['hours'] === 1 ? 'hour' : 'hours' }}</span></div>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        {{-- Needs attention: panel with the 2px amber left rule. Never for a reader. --}}
                        @if (!$reader && count($attention) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                {!! $spacer(28) !!}
                                <tr>
                                    <td style="padding-bottom: 12px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td class="nv-text" style="{{ $h2 }}">Needs attention</td>
                                                <td align="right" style="font-family: {{ $sans }}; font-size: 13px; font-weight: 600; white-space: nowrap;">
                                                    <a class="nv-link" href="{{ $links['health'] }}" style="color: #8EA2FF; text-decoration: none;">Health &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; border: 1px solid #262D38; border-left: 2px solid #F0B429; border-collapse: separate;">
                                @foreach ($attention as $item)
                                    <tr>
                                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 12px 16px; {{ $loop->first ? '' : 'border-top: 1px solid #262D38;' }}">
                                            <div style="font-family: {{ $sans }}; font-size: 14px; line-height: 1.4; font-weight: 600;">
                                                @if (!empty($item['id']))
                                                    <a class="nv-link" href="{{ route('novels.show', $item['id']) }}" style="color: #8EA2FF; text-decoration: none;">{{ $item['name'] }}</a>
                                                @else
                                                    <span class="nv-text" style="color: #E8EBF0;">{{ $item['name'] }}</span>
                                                @endif
                                            </div>
                                            <div style="padding-top: 4px; font-family: {{ $sans }}; font-size: 13px; line-height: 1.55; color: #D8DDE6;">{{ $item['reason'] }}</div>
                                            <div style="padding-top: 6px; font-family: {{ $mono }}; font-size: 11px; line-height: 1.6; letter-spacing: 0.04em;">
                                                @if (!empty($item['url']))
                                                    <a class="nv-link" href="{{ $item['url'] }}" style="color: #8EA2FF; text-decoration: none;">TEST SOURCE &#8599;</a><span style="color: #5B6472;"> &nbsp;·&nbsp; </span>
                                                @endif
                                                <a class="nv-link" href="{{ $links['health'] }}" style="color: #8EA2FF; text-decoration: none;">HEALTH &rarr;</a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        @if (count($novels) === 0 && count($manga) === 0 && count($completed) === 0 && count($attention) === 0 && count($stalled) === 0)
                            <p class="nv-muted" style="margin: 20px 0 0; font-family: {{ $sans }}; font-size: 14px; line-height: 1.55; color: #8B95A5;">Nothing new in this period.</p>
                        @endif

                    </td>
                </tr>

@endsection

@section('footer_note')
@if ($reader)
@if (!empty($reader['name']))For {{ $reader['name'] }} · @endif Daily at {{ $v['summary_time'] }} · turn off in Account
@if (!empty($links['unsubscribe']))
· <a class="nv-link" href="{{ $links['unsubscribe'] }}" style="color: #8EA2FF; text-decoration: none;">Stop these emails</a>
@endif
@else
Daily at {{ $v['summary_time'] }} · change in Settings
@endif
@endsection

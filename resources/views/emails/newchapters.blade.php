<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- Dark is the canonical Novarr theme; tell the client so it doesn't try to "fix" it. --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Novarr – Daily Summary</title>
    <style type="text/css">
        /* The only stylesheet in the file. Everything that matters is inline;
           this block exists purely for clients that repaint dark mail
           (Outlook.com rewrites colours behind [data-ogsc]/[data-ogsb]). */
        [data-ogsc] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsc] .nv-surface { background-color: #161A20 !important; }
        [data-ogsc] .nv-text { color: #E8EBF0 !important; }
        [data-ogsc] .nv-muted { color: #8B95A5 !important; }
        [data-ogsc] .nv-link { color: #8EA2FF !important; }
        [data-ogsb] .nv-ground { background-color: #0F1216 !important; }
        [data-ogsb] .nv-surface { background-color: #161A20 !important; }
    </style>
</head>
@php
    /** @var array $v  normalised view model — see App\Mail\NewChapters::viewData() */
    $tz = config('app.timezone');
    $continue = $v['continue'];
    $stats = $v['stats'];
    $novels = $v['novels'];
    $completed = $v['completed'];
    $attention = $v['attention'];
    $links = $v['links'];

    // Brand tokens (design_handoff_novarr_brand). No web fonts, no classes in
    // the markup beyond the nv-* dark-mode hooks — every value is inlined.
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $serif = "Georgia, 'Times New Roman', Times, serif";              // stands in for Literata
    $mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";

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
    $spacer = fn (int $h) => '<tr><td height="' . $h . '" style="height: ' . $h . 'px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>';

    $preheader = collect([
        $stats['new_chapters'] ? number_format($stats['new_chapters']) . ' new chapters' : null,
        $stats['completed'] ? $stats['completed'] . ' completed' : null,
        $stats['attention'] ? $stats['attention'] . ' need attention' : null,
        $continue ? 'Continue ' . $continue['name'] : null,
    ])->filter()->implode(' · ');

    // Stat tiles: 3 + 2 so they survive a 320px viewport.
    $tile = function (string $name, $value, string $tone) {
        return ['name' => $name, 'value' => $value, 'tone' => $tone];
    };
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
@endphp
<body class="nv-ground" style="margin: 0; padding: 0; width: 100%; background-color: #0F1216; color: #E8EBF0; font-family: {{ $sans }}; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

{{-- Inbox preview line; hidden everywhere else. --}}
<div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;&#8204;&nbsp;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 100%; background-color: #0F1216; margin: 0; padding: 0;">
    <tr>
        <td align="center" bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 8px 24px;">
            <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 100%; max-width: 600px; background-color: #0F1216; border: 1px solid #262D38; border-collapse: separate;">

                {{-- ── Header: Serial mark + wordmark left, mono date right, 2px accent rule under ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 18px 20px; border-bottom: 2px solid #6470FF;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" valign="middle" style="vertical-align: middle;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                        <tr>
                                            {{-- "Serial" mark on a 26px grid, drawn with table cells (images and
                                                 inline SVG are unreliable in Gmail/Outlook): four 3px bars with
                                                 2px gaps, 22/22/15/8 wide, the last one amber. --}}
                                            <td width="22" valign="middle" style="width: 22px; vertical-align: middle; padding-right: 12px;">
                                                <table role="presentation" width="22" cellpadding="0" cellspacing="0" border="0" style="width: 22px; border-collapse: collapse;">
                                                    <tr><td height="4" style="height: 4px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                    @foreach ([[22, '#6470FF'], [22, '#6470FF'], [15, '#6470FF'], [8, '#F0B429']] as [$w, $c])
                                                        <tr>
                                                            <td align="left" style="font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">
                                                                <table role="presentation" width="{{ $w }}" cellpadding="0" cellspacing="0" border="0" style="width: {{ $w }}px; border-collapse: collapse;">
                                                                    <tr><td width="{{ $w }}" height="3" bgcolor="{{ $c }}" style="width: {{ $w }}px; height: 3px; background-color: {{ $c }}; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        @unless ($loop->last)
                                                            <tr><td height="2" style="height: 2px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                        @endunless
                                                    @endforeach
                                                    <tr><td height="4" style="height: 4px; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td></tr>
                                                </table>
                                            </td>
                                            <td valign="middle" style="vertical-align: middle;">
                                                <span class="nv-text" style="font-family: {{ $sans }}; font-size: 14px; font-weight: 700; letter-spacing: 0.16em; color: #E8EBF0;">NOVARR<span style="color: #F0B429;">.</span></span>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td align="right" valign="middle" class="nv-muted" style="vertical-align: middle; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.08em; color: #8B95A5; white-space: nowrap;">
                                    {{ strtoupper($v['date']->format('D d M Y')) }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

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
                                                <td align="center" bgcolor="#6470FF" style="background-color: #6470FF; border-radius: 4px;">
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

                {{-- ── Stats strip: 3 + 2 tiles, mono figures, muted when zero ── --}}
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
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                {!! $spacer(20) !!}
                                <tr>
                                    <td style="padding-bottom: 12px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td class="nv-text" style="{{ $h2 }}">New chapters</td>
                                                <td align="right" class="nv-muted" style="font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #8B95A5; white-space: nowrap;">{{ number_format($stats['new_chapters']) }} IN {{ number_format($stats['novels_updated']) }} {{ (int) $stats['novels_updated'] === 1 ? 'NOVEL' : 'NOVELS' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; border: 1px solid #262D38; border-collapse: separate;">
                                @foreach ($novels as $novel)
                                    @php
                                        $pct = $novel['percent'];
                                    @endphp
                                    <tr>
                                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 14px 16px 14px; {{ $loop->first ? '' : 'border-top: 1px solid #262D38;' }}" data-novel-row="{{ $novel['id'] }}">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-family: {{ $serif }}; font-size: 16px; line-height: 1.35; font-weight: 700; color: #E8EBF0;">
                                                        @if ($novel['url'])
                                                            <a class="nv-text" href="{{ $novel['url'] }}" style="color: #E8EBF0; text-decoration: none;">{{ $novel['name'] }}</a>
                                                        @else
                                                            <span class="nv-text" style="color: #E8EBF0;">{{ $novel['name'] }}</span>
                                                        @endif
                                                        @if (($novel['notes'] ?? 0) > 0)
                                                            &nbsp;<span style="{{ $chipNote }}">Author's note</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="nv-muted" style="padding-top: 4px; font-family: {{ $mono }}; font-size: 12px; line-height: 1.55; color: #8B95A5;">
                                                        {{ \App\Mail\NewChapters::chapterRange($novel) }} · <span style="color: #E8EBF0;">{{ number_format($novel['count']) }} new</span>@if ($novel['source']) <span style="white-space: nowrap;">· {{ $novel['source'] }}</span>@endif
                                                    </td>
                                                </tr>
                                                @if ($pct !== null)
                                                    {{-- Reading progress (read ÷ total, as on Home): 4px amber bar on #1C222A. --}}
                                                    <tr>
                                                        <td style="padding-top: 10px;">
                                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1C222A" style="width: 100%; background-color: #1C222A; border-collapse: collapse;">
                                                                <tr>
                                                                    @if ($pct > 0)
                                                                        <td width="{{ $pct }}%" height="4" bgcolor="#F0B429" style="width: {{ $pct }}%; height: 4px; background-color: #F0B429; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td>
                                                                    @endif
                                                                    @if ($pct < 100)
                                                                        <td width="{{ 100 - $pct }}%" height="4" bgcolor="#1C222A" style="width: {{ 100 - $pct }}%; height: 4px; background-color: #1C222A; font-size: 1px; line-height: 1px; mso-line-height-rule: exactly;">&nbsp;</td>
                                                                    @endif
                                                                </tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding-top: 8px;">
                                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                                <tr>
                                                                    <td class="nv-muted" style="font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #8B95A5; white-space: nowrap;">{{ $pct }}% READ</td>
                                                                    @if ($novel['read_from_url'])
                                                                        <td align="right" style="font-family: {{ $sans }}; font-size: 13px; font-weight: 600;">
                                                                            <a class="nv-link" href="{{ $novel['read_from_url'] }}" style="color: #8EA2FF; text-decoration: none; white-space: nowrap;">Read from {{ \App\Mail\NewChapters::chapterNumber($novel['read_from']) }} &rarr;</a>
                                                                        </td>
                                                                    @endif
                                                                </tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                @endif
                                            </table>
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($v['more_novels'] > 0)
                                    <tr>
                                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 12px 16px; border-top: 1px solid #262D38; font-family: {{ $sans }}; font-size: 13px;">
                                            <a class="nv-link" href="{{ $links['activity'] }}" style="color: #8EA2FF; text-decoration: none;">+{{ number_format($v['more_novels']) }} more {{ $v['more_novels'] === 1 ? 'novel' : 'novels' }} on Activity &rarr;</a>
                                        </td>
                                    </tr>
                                @endif
                            </table>
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

                        {{-- Needs attention: panel with the 2px amber left rule --}}
                        @if (count($attention) > 0)
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

                        @if (count($novels) === 0 && count($completed) === 0 && count($attention) === 0)
                            <p class="nv-muted" style="margin: 20px 0 0; font-family: {{ $sans }}; font-size: 14px; line-height: 1.55; color: #8B95A5;">Nothing new in this period.</p>
                        @endif

                    </td>
                </tr>

                {{-- ── Footer ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 20px 20px; border-top: 1px solid #262D38;">
                        <div style="font-family: {{ $sans }}; font-size: 13px; line-height: 1.6; font-weight: 600;">
                            <a class="nv-link" href="{{ $links['home'] }}" style="color: #8EA2FF; text-decoration: none;">Open Novarr</a><span style="color: #5B6472;"> &nbsp;·&nbsp; </span><a class="nv-link" href="{{ $links['activity'] }}" style="color: #8EA2FF; text-decoration: none;">Activity</a><span style="color: #5B6472;"> &nbsp;·&nbsp; </span><a class="nv-link" href="{{ $links['settings'] }}" style="color: #8EA2FF; text-decoration: none;">Settings</a>
                        </div>
                        <div class="nv-muted" style="padding-top: 6px; font-family: {{ $mono }}; font-size: 11px; line-height: 1.6; color: #6B7684;">
                            Daily at {{ $v['summary_time'] }} · change in Settings
                        </div>
                    </td>
                </tr>

            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td>
    </tr>
</table>
</body>
</html>

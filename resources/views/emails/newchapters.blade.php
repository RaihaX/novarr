<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
    </style>
</head>
@php
    // Support both payload shapes: the daily summary passes ['chapters' => [...], 'completed' => [...], 'since' => ...],
    // the legacy path passes a flat array of chapter rows.
    $chapters = $data['chapters'] ?? (is_array($data) && array_is_list($data) ? $data : []);
    $completed = $data['completed'] ?? [];
    $attention = $data['attention'] ?? [];
    $since = $data['since'] ?? null;
    $byNovel = collect($chapters)->groupBy('novel')->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);

    $fmtChapter = fn ($item) => (($item['book'] ?? 0) > 0 ? 'B' . $item['book'] . ' · ' : '')
        . rtrim(rtrim(number_format((float) $item['chapter'], 2, '.', ''), '0'), '.');

    // Brand tokens (see design_handoff_novarr_brand/_variables.scss). No web
    // fonts and no classes in the markup — every value is inlined below.
    $sans = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $serif = "Georgia, 'Times New Roman', Times, serif";              // stands in for Literata
    $mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";

    // Status triad — full-value text, 12% fill, 35% border. Email clients (Outlook
    // especially) do not support rgba(), so the alphas are pre-composited against
    // the ground (#0F1216) and the raised surface (#161A20).
    $chip = function (string $text, string $fill, string $border) use ($sans) {
        return '<span style="display: inline-block; font-family: ' . $sans . '; font-size: 11px; font-weight: 600; '
            . 'letter-spacing: 0.14em; text-transform: uppercase; color: ' . $text . '; background-color: ' . $fill . '; '
            . 'border: 1px solid ' . $border . '; padding: 3px 8px; white-space: nowrap;">';
    };
    // over the raised surface #161A20
    $chipAccent = $chip('#8EA2FF', '#1F243B', '#31386E');
    // over the ground #0F1216
    $chipSuccess = $chip('#3FB950', '#15261D', '#204C2A');
    $chipWarning = $chip('#F0B429', '#2A2518', '#5E4B1D');
@endphp
<body class="nv-ground" style="margin: 0; padding: 0; background-color: #0F1216; color: #E8EBF0; font-family: {{ $sans }}; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; margin: 0; padding: 0;">
    <tr>
        <td align="center" bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#0F1216" class="nv-ground" style="width: 600px; max-width: 600px; background-color: #0F1216; border: 1px solid #262D38;">

                {{-- ── Header: mark + wordmark left, mono label right, 2px accent rule under ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 20px 24px; border-bottom: 2px solid #6470FF;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" valign="middle" style="vertical-align: middle;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                        <tr>
                                            {{-- 26px brand mark, drawn with table cells: images (including
                                                 data: URIs) and inline SVG are unreliable in Gmail/Outlook,
                                                 so the three spines and the amber bookmark are coloured cells. --}}
                                            <td valign="middle" style="vertical-align: middle; padding-right: 10px;">
                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                                    <tr>
                                                        <td width="5" height="26" bgcolor="#6470FF" style="width: 5px; height: 26px; background-color: #6470FF; font-size: 0; line-height: 0;">&nbsp;</td>
                                                        <td width="3" style="width: 3px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                        <td width="5" height="26" bgcolor="#7E6EFF" style="width: 5px; height: 26px; background-color: #7E6EFF; font-size: 0; line-height: 0;">&nbsp;</td>
                                                        <td width="3" style="width: 3px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                        <td width="5" valign="top" style="width: 5px; vertical-align: top; font-size: 0; line-height: 0;">
                                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
                                                                <tr><td width="5" height="8" bgcolor="#F0B429" style="width: 5px; height: 8px; background-color: #F0B429; font-size: 0; line-height: 0;">&nbsp;</td></tr>
                                                                <tr><td width="5" height="18" bgcolor="#9B6BFF" style="width: 5px; height: 18px; background-color: #9B6BFF; font-size: 0; line-height: 0;">&nbsp;</td></tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                            <td valign="middle" style="vertical-align: middle;">
                                                <span class="nv-text" style="font-family: {{ $sans }}; font-size: 14px; font-weight: 700; letter-spacing: 0.16em; color: #E8EBF0;">NOVARR<span style="color: #F0B429;">.</span></span>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                                <td align="right" valign="middle" style="vertical-align: middle;">
                                    <span class="nv-muted" style="font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.08em; color: #8B95A5; white-space: nowrap;">
                                        {{ strtoupper(\Illuminate\Support\Carbon::now()->timezone(config('app.timezone'))->format('d M Y')) }}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── Stats strip ── --}}
                <tr>
                    <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 16px 24px; border-bottom: 1px solid #262D38;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="33%" align="left" style="padding-right: 8px;">
                                    <span class="nv-text" style="font-family: {{ $mono }}; font-size: 24px; font-weight: 600; color: #E8EBF0;">{{ count($chapters) }}</span><br>
                                    <span class="nv-muted" style="font-family: {{ $sans }}; font-size: 11px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8B95A5;">Chapters</span>
                                </td>
                                <td width="34%" align="left" style="padding: 0 8px; border-left: 1px solid #262D38; border-right: 1px solid #262D38;">
                                    <span class="nv-text" style="font-family: {{ $mono }}; font-size: 24px; font-weight: 600; color: #E8EBF0;">{{ $byNovel->count() }}</span><br>
                                    <span class="nv-muted" style="font-family: {{ $sans }}; font-size: 11px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8B95A5;">Novels</span>
                                </td>
                                <td width="33%" align="left" style="padding-left: 8px;">
                                    <span class="nv-text" style="font-family: {{ $mono }}; font-size: 24px; font-weight: 600; color: #E8EBF0;">{{ count($completed) }}</span><br>
                                    <span class="nv-muted" style="font-family: {{ $sans }}; font-size: 11px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8B95A5;">Completed</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── Body ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 24px;">

                        @if ($since)
                            <p class="nv-muted" style="margin: 0 0 20px; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.08em; color: #8B95A5;">
                                SINCE {{ strtoupper(\Illuminate\Support\Carbon::parse($since)->timezone(config('app.timezone'))->format('d M Y, g:i A')) }}
                            </p>
                        @endif

                        {{-- Novels marked complete --}}
                        @if (count($completed) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td class="nv-text" style="font-family: {{ $sans }}; font-size: 18px; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0; padding-bottom: 12px;">Completed</td>
                                </tr>
                                @foreach ($completed as $novel)
                                    <tr>
                                        <td bgcolor="#15261D" style="background-color: #15261D; border: 1px solid #204C2A; padding: 12px 16px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td class="nv-text" style="font-family: {{ $serif }}; font-size: 15px; font-weight: 700; color: #E8EBF0;">{{ $novel['name'] }}</td>
                                                    <td align="right" style="white-space: nowrap; padding-left: 12px;">
                                                        {!! $chipSuccess !!}Complete</span>
                                                    </td>
                                                </tr>
                                                @if (!empty($novel['completed_at']))
                                                    <tr>
                                                        <td colspan="2" style="padding-top: 6px; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #3FB950;">
                                                            {{ strtoupper(\Illuminate\Support\Carbon::parse($novel['completed_at'])->timezone(config('app.timezone'))->format('d M Y')) }}
                                                        </td>
                                                    </tr>
                                                @endif
                                            </table>
                                        </td>
                                    </tr>
                                    @if (!$loop->last)
                                        <tr><td height="8" style="height: 8px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
                                    @endif
                                @endforeach
                            </table>
                        @endif

                        {{-- Novels needing attention --}}
                        @if (count($attention) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td class="nv-text" style="font-family: {{ $sans }}; font-size: 18px; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0; padding-bottom: 12px;">Needs attention</td>
                                </tr>
                                @foreach ($attention as $novel)
                                    <tr>
                                        <td bgcolor="#2A2518" style="background-color: #2A2518; border: 1px solid #5E4B1D; padding: 12px 16px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td class="nv-text" style="font-family: {{ $serif }}; font-size: 15px; font-weight: 700; color: #E8EBF0;">{{ $novel['name'] }}</td>
                                                    <td align="right" style="white-space: nowrap; padding-left: 12px;">
                                                        {!! $chipWarning !!}Attention</span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" style="padding-top: 6px; font-family: {{ $sans }}; font-size: 13px; line-height: 1.55; color: #D8DDE6;">{{ $novel['reason'] }}</td>
                                                </tr>
                                                @if (!empty($novel['url']))
                                                    <tr>
                                                        <td colspan="2" style="padding-top: 8px;">
                                                            <a class="nv-link" href="{{ $novel['url'] }}" style="font-family: {{ $mono }}; font-size: 11px; color: #8EA2FF; text-decoration: underline; word-break: break-all;">Test source ↗ {{ \Illuminate\Support\Str::limit($novel['url'], 62) }}</a>
                                                        </td>
                                                    </tr>
                                                @endif
                                            </table>
                                        </td>
                                    </tr>
                                    @if (!$loop->last)
                                        <tr><td height="8" style="height: 8px; font-size: 0; line-height: 0;">&nbsp;</td></tr>
                                    @endif
                                @endforeach
                            </table>
                        @endif

                        {{-- New chapters, grouped per novel --}}
                        @if ($byNovel->isNotEmpty())
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="nv-text" style="font-family: {{ $sans }}; font-size: 18px; font-weight: 600; letter-spacing: -0.01em; color: #E8EBF0; padding-bottom: 12px;">New chapters</td>
                                </tr>
                            </table>

                            @foreach ($byNovel as $novelName => $items)
                                @php
                                    $progress = min(100, (float) str_replace(',', '', collect($items)->max(fn ($i) => (float) str_replace(',', '', $i['progress']))));
                                    $progressLabel = collect($items)->sortByDesc(fn ($i) => (float) str_replace(',', '', $i['progress']))->first()['progress'];
                                    $barWidth = max(1, min(99, (int) round($progress)));
                                @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; border: 1px solid #262D38; margin-bottom: 12px;">
                                    {{-- Novel header --}}
                                    <tr>
                                        <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 14px 16px 12px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td class="nv-text" style="font-family: {{ $serif }}; font-size: 15px; font-weight: 700; color: #E8EBF0;">{{ $novelName }}</td>
                                                    <td align="right" style="white-space: nowrap; padding-left: 12px;">
                                                        {!! $chipAccent !!}{{ count($items) }} CH</span>
                                                    </td>
                                                </tr>
                                            </table>

                                            {{-- Reading progress: 4px bar, flat, track #1C222A --}}
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 12px;">
                                                <tr>
                                                    <td>
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#1C222A" style="background-color: #1C222A; border-collapse: collapse;">
                                                            <tr>
                                                                <td width="{{ $barWidth }}%" height="4" bgcolor="#6470FF" style="width: {{ $barWidth }}%; height: 4px; background-color: #6470FF; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                <td width="{{ 100 - $barWidth }}%" height="4" bgcolor="#1C222A" style="width: {{ 100 - $barWidth }}%; height: 4px; background-color: #1C222A; font-size: 0; line-height: 0;">&nbsp;</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td width="64" align="right" style="width: 64px; padding-left: 10px; white-space: nowrap; font-family: {{ $mono }}; font-size: 11px; color: #8EA2FF;">{{ $progressLabel }}%</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>

                                    {{-- Chapter rows --}}
                                    @foreach (collect($items)->take(50) as $item)
                                        <tr>
                                            <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 8px 16px; border-top: 1px solid #262D38;">
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td width="92" valign="top" style="width: 92px; vertical-align: top; white-space: nowrap; font-family: {{ $mono }}; font-size: 12px; color: #8EA2FF;">{{ $fmtChapter($item) }}</td>
                                                        <td valign="top" style="vertical-align: top; font-family: {{ $sans }}; font-size: 14px; line-height: 1.55; color: #D8DDE6;">{{ $item['label'] }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endforeach
                                    @if (count($items) > 50)
                                        <tr>
                                            <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 8px 16px; border-top: 1px solid #262D38; font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #6B7684;">
                                                + {{ count($items) - 50 }} MORE CHAPTER{{ count($items) - 50 === 1 ? '' : 'S' }}
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            @endforeach
                        @else
                            <p class="nv-muted" style="margin: 0; font-family: {{ $sans }}; font-size: 14px; line-height: 1.55; color: #8B95A5;">No new chapters were downloaded in this period.</p>
                        @endif

                    </td>
                </tr>

                {{-- ── Footer ── --}}
                <tr>
                    <td bgcolor="#0F1216" class="nv-ground" style="background-color: #0F1216; padding: 16px 24px 20px; border-top: 1px solid #262D38;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" class="nv-muted" style="font-family: {{ $sans }}; font-size: 11px; font-weight: 600; letter-spacing: 0.16em; color: #8B95A5;">NOVARR<span style="color: #F0B429;">.</span></td>
                                <td align="right" class="nv-muted" style="font-family: {{ $mono }}; font-size: 11px; color: #6B7684;">
                                    <a class="nv-link" href="{{ config('app.url') }}" style="color: #6B7684; text-decoration: none;">{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>

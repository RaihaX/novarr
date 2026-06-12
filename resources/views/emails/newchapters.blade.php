<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Novarr – Daily Summary</title>
</head>
<body style="margin: 0; padding: 0; background-color: #eef0f4; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1f2937; -webkit-text-size-adjust: 100%;">
@php
    // Support both payload shapes: the daily summary passes ['chapters' => [...], 'completed' => [...], 'since' => ...],
    // the legacy path passes a flat array of chapter rows.
    $chapters = $data['chapters'] ?? (is_array($data) && array_is_list($data) ? $data : []);
    $completed = $data['completed'] ?? [];
    $since = $data['since'] ?? null;
    $byNovel = collect($chapters)->groupBy('novel');

    $fmtChapter = fn ($item) => (($item['book'] ?? 0) > 0 ? 'B' . $item['book'] . ' · ' : '')
        . rtrim(rtrim(number_format((float) $item['chapter'], 2, '.', ''), '0'), '.');
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #eef0f4; padding: 24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%;">

                {{-- Header --}}
                <tr>
                    <td bgcolor="#312e81" style="background-color: #312e81; border-radius: 12px 12px 0 0; padding: 28px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td>
                                    <span style="font-size: 24px; font-weight: 700; color: #ffffff; letter-spacing: 0.5px;">📚 Novarr</span><br>
                                    <span style="font-size: 13px; color: #c7d2fe;">Daily Summary
                                    @if ($since)
                                        · since {{ \Illuminate\Support\Carbon::parse($since)->timezone(config('app.timezone'))->format('j M Y, g:i A') }}
                                    @endif
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Stats strip --}}
                <tr>
                    <td bgcolor="#3730a3" style="background-color: #3730a3; padding: 16px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="33%" align="center" style="padding: 4px;">
                                    <span style="font-size: 26px; font-weight: 700; color: #ffffff;">{{ count($chapters) }}</span><br>
                                    <span style="font-size: 11px; color: #c7d2fe; text-transform: uppercase; letter-spacing: 1px;">Chapters</span>
                                </td>
                                <td width="33%" align="center" style="padding: 4px; border-left: 1px solid #4f46e5; border-right: 1px solid #4f46e5;">
                                    <span style="font-size: 26px; font-weight: 700; color: #ffffff;">{{ $byNovel->count() }}</span><br>
                                    <span style="font-size: 11px; color: #c7d2fe; text-transform: uppercase; letter-spacing: 1px;">Novels updated</span>
                                </td>
                                <td width="33%" align="center" style="padding: 4px;">
                                    <span style="font-size: 26px; font-weight: 700; color: #ffffff;">{{ count($completed) }}</span><br>
                                    <span style="font-size: 11px; color: #c7d2fe; text-transform: uppercase; letter-spacing: 1px;">Completed</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td bgcolor="#ffffff" style="background-color: #ffffff; border-radius: 0 0 12px 12px; padding: 28px 32px;">

                        {{-- Completed novels --}}
                        @if (count($completed) > 0)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="font-size: 16px; font-weight: 700; color: #1f2937; padding-bottom: 10px;">🎉 Novels marked complete</td>
                                </tr>
                                @foreach ($completed as $novel)
                                    <tr>
                                        <td bgcolor="#ecfdf5" style="background-color: #ecfdf5; border-left: 4px solid #10b981; border-radius: 6px; padding: 12px 16px; {{ $loop->last ? '' : 'border-bottom: 6px solid #ffffff;' }}">
                                            <span style="font-size: 14px; font-weight: 600; color: #065f46;">{{ $novel['name'] }}</span>
                                            @if (!empty($novel['completed_at']))
                                                <br><span style="font-size: 12px; color: #059669;">Completed {{ \Illuminate\Support\Carbon::parse($novel['completed_at'])->timezone(config('app.timezone'))->format('j M Y') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        {{-- Chapters per novel --}}
                        @if ($byNovel->isNotEmpty())
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size: 16px; font-weight: 700; color: #1f2937; padding-bottom: 4px;">⬇️ New chapters</td>
                                </tr>
                            </table>

                            @foreach ($byNovel as $novelName => $items)
                                @php
                                    $progress = min(100, (float) str_replace(',', '', collect($items)->max(fn ($i) => (float) str_replace(',', '', $i['progress']))));
                                    $progressLabel = collect($items)->sortByDesc(fn ($i) => (float) str_replace(',', '', $i['progress']))->first()['progress'];
                                    $barWidth = max(1, (int) round($progress));
                                @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 14px; border: 1px solid #e5e7eb; border-radius: 8px;">
                                    {{-- Novel header --}}
                                    <tr>
                                        <td bgcolor="#f9fafb" style="background-color: #f9fafb; border-radius: 8px 8px 0 0; padding: 12px 16px 10px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                <tr>
                                                    <td style="font-size: 14px; font-weight: 700; color: #111827;">{{ $novelName }}</td>
                                                    <td align="right" style="font-size: 12px; color: #6b7280; white-space: nowrap;">{{ count($items) }} chapter{{ count($items) === 1 ? '' : 's' }}</td>
                                                </tr>
                                            </table>
                                            {{-- Progress bar --}}
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px;">
                                                <tr>
                                                    <td>
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
                                                            <tr>
                                                                <td width="{{ $barWidth }}%" height="6" bgcolor="#6366f1" style="background-color: #6366f1; border-radius: 3px 0 0 3px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                <td width="{{ 100 - $barWidth }}%" height="6" bgcolor="#e5e7eb" style="background-color: #e5e7eb; border-radius: 0 3px 3px 0; font-size: 0; line-height: 0;">&nbsp;</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td width="56" align="right" style="font-size: 11px; font-weight: 600; color: #6366f1; padding-left: 8px; white-space: nowrap;">{{ $progressLabel }}%</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    {{-- Chapter rows --}}
                                    @foreach (collect($items)->take(50) as $item)
                                        <tr>
                                            <td style="padding: 8px 16px; border-top: 1px solid #f3f4f6;">
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td width="80" style="font-size: 12px; font-weight: 600; color: #6366f1; white-space: nowrap; vertical-align: top;">{{ $fmtChapter($item) }}</td>
                                                        <td style="font-size: 13px; color: #374151;">{{ $item['label'] }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endforeach
                                    @if (count($items) > 50)
                                        <tr>
                                            <td style="padding: 8px 16px; border-top: 1px solid #f3f4f6; font-size: 12px; color: #9ca3af; font-style: italic;">
                                                … and {{ count($items) - 50 }} more chapter{{ count($items) - 50 === 1 ? '' : 's' }}
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            @endforeach
                        @else
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">No new chapters were downloaded in this period.</p>
                        @endif

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td align="center" style="padding: 20px 32px;">
                        <span style="font-size: 12px; color: #9ca3af;">Sent automatically by Novarr · novarr.atomicinsights.com.au</span>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>

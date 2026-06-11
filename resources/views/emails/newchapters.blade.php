<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Novarr – New Chapters</title>
</head>
<body style="margin: 0; padding: 24px; background-color: #f4f4f7; font-family: Helvetica, Arial, sans-serif; color: #333;">
@php
    // Support both payload shapes: the daily summary passes ['chapters' => [...], 'completed' => [...], 'since' => ...],
    // the legacy path passes a flat array of chapter rows.
    $chapters = $data['chapters'] ?? (is_array($data) && array_is_list($data) ? $data : []);
    $completed = $data['completed'] ?? [];
    $since = $data['since'] ?? null;
    $byNovel = collect($chapters)->groupBy('novel');
@endphp

<div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; padding: 32px;">
    <h1 style="margin: 0 0 4px; font-size: 22px;">📚 Novarr Daily Summary</h1>
    @if ($since)
        <p style="margin: 0 0 24px; color: #888; font-size: 13px;">
            New chapters since {{ \Illuminate\Support\Carbon::parse($since)->timezone(config('app.timezone'))->format('j M Y, g:i A') }}
        </p>
    @endif

    @if (count($completed) > 0)
        <h2 style="font-size: 16px; margin: 24px 0 8px;">🎉 Novels marked complete</h2>
        <ul style="margin: 0 0 16px; padding-left: 20px;">
            @foreach ($completed as $novel)
                <li style="margin-bottom: 4px;">
                    <strong>{{ $novel['name'] }}</strong>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($byNovel->isNotEmpty())
        <h2 style="font-size: 16px; margin: 24px 0 8px;">⬇️ Chapters downloaded ({{ count($chapters) }})</h2>
        @foreach ($byNovel as $novelName => $items)
            <h3 style="font-size: 14px; margin: 16px 0 6px;">{{ $novelName }} <span style="color: #888; font-weight: normal;">({{ count($items) }} chapter{{ count($items) === 1 ? '' : 's' }})</span></h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd;">Chapter</th>
                        <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd;">Label</th>
                        <th style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #ddd;">Progress</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (collect($items)->take(50) as $item)
                        <tr>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;">
                                {{ ($item['book'] ?? 0) > 0 ? 'B' . $item['book'] . ' · ' : '' }}{{ rtrim(rtrim(number_format((float) $item['chapter'], 2, '.', ''), '0'), '.') }}
                            </td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f0;">{{ $item['label'] }}</td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f0f0f0; text-align: right;">{{ $item['progress'] }}%</td>
                        </tr>
                    @endforeach
                    @if (count($items) > 50)
                        <tr>
                            <td colspan="3" style="padding: 6px 8px; color: #888; font-style: italic;">… and {{ count($items) - 50 }} more chapter{{ count($items) - 50 === 1 ? '' : 's' }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        @endforeach
    @else
        <p style="color: #888;">No new chapters were downloaded in this period.</p>
    @endif

    <p style="margin-top: 32px; color: #aaa; font-size: 12px;">Sent automatically by Novarr.</p>
</div>
</body>
</html>

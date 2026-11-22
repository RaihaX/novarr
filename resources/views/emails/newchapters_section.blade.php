{{--
    One "New chapters"-style section of the daily summary (novels, manga):
    heading with a mono figure on the right, one row per series, and a
    "+N more … on Activity" footer. Included from emails.newchapters, so the
    brand tokens ($sans, $serif, $mono, $h2, $chipNote, $spacer, $links,
    $reader) come from there.

    $title   section heading
    $figure  right-hand figure ("120 IN 4 NOVELS")
    $rows    series rows (App\Mail\NewChapters::viewData())
    $more    rows not shown
    $one     the footer noun ("novel", "manga")
    $many    its plural
    $gap     space above the heading (optional, 20)

    A reader ($reader) has no Activity page: "+N more" links to Novarr's
    home instead. That branch's directives sit at the left margin so the
    owner's markup keeps its exact bytes.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    {!! $spacer($gap ?? 20) !!}
    <tr>
        <td style="padding-bottom: 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td class="nv-text" style="{{ $h2 }}">{{ $title }}</td>
                    <td align="right" class="nv-muted" style="font-family: {{ $mono }}; font-size: 11px; letter-spacing: 0.06em; color: #8B95A5; white-space: nowrap;">{{ $figure }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; border: 1px solid #262D38; border-collapse: separate;">
    @foreach ($rows as $novel)
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
                            {{ \App\Mail\NewChapters::chapterRange($novel) }} · <span style="color: #E8EBF0;">{{ number_format($novel['count']) }} new</span>@if ($novel['source']) <span style="white-space: nowrap;">· {{ $novel['source'] }}</span>@endif @if (!empty($novel['origin'])) <span style="white-space: nowrap;">· {{ $novel['origin'] }}</span>@endif
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
    @if ($more > 0)
        <tr>
            <td bgcolor="#161A20" class="nv-surface" style="background-color: #161A20; padding: 12px 16px; border-top: 1px solid #262D38; font-family: {{ $sans }}; font-size: 13px;">
@if (!empty($reader))
                <a class="nv-link" href="{{ $links['home'] }}" style="color: #8EA2FF; text-decoration: none;">+{{ number_format($more) }} more {{ $more === 1 ? $one : $many }} in Novarr &rarr;</a>
@else
                <a class="nv-link" href="{{ $links['activity'] }}" style="color: #8EA2FF; text-decoration: none;">+{{ number_format($more) }} more {{ $more === 1 ? $one : $many }} on Activity &rarr;</a>
@endif
            </td>
        </tr>
    @endif
</table>

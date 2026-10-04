@php
    /** Plain-text part of App\Mail\NewChapters — same sections as the HTML, URLs spelled out. */
    $tz = config('app.timezone');
    $s = $v['stats'];
    $fig = fn ($n) => $n === null ? '-' : number_format((int) $n);
@endphp
NOVARR. — Daily summary, {{ $v['date']->format('D d M Y') }}
@if ($v['since'])
Since {{ \Illuminate\Support\Carbon::parse($v['since'])->timezone($tz)->format('D d M, g:i A') }}
@endif
@if ($v['continue'])

CONTINUE READING
{!! $v['continue']['name'] !!}
Chapter {{ number_format($v['continue']['index']) }} of {{ number_format($v['continue']['total']) }} · {{ $v['continue']['chapter_percent'] }}% of chapter
Resume: {!! $v['continue']['url'] ?? route('continue') !!}
@endif

New chapters: {{ $fig($s['new_chapters']) }} · Novels updated: {{ $fig($s['novels_updated']) }} · Completed: {{ $fig($s['completed']) }} · Queued: {{ $fig($s['queued']) }} · Needs attention: {{ $fig($s['attention']) }}
@if (count($v['novels']) > 0)

NEW CHAPTERS
@foreach ($v['novels'] as $novel)

- {!! $novel['name'] !!}{!! ($novel['notes'] ?? 0) > 0 ? " [Author's note]" : '' !!}
  {{ \App\Mail\NewChapters::chapterRange($novel) }} · {{ number_format($novel['count']) }} new{!! $novel['source'] ? ' · ' . $novel['source'] : '' !!}
@if ($novel['percent'] !== null)
  {{ $novel['percent'] }}% read
@endif
@if ($novel['read_from_url'])
  Read from {{ \App\Mail\NewChapters::chapterNumber($novel['read_from']) }}: {!! $novel['read_from_url'] !!}
@elseif ($novel['url'])
  {!! $novel['url'] !!}
@endif
@endforeach
@if ($v['more_novels'] > 0)

+{{ number_format($v['more_novels']) }} more {{ $v['more_novels'] === 1 ? 'novel' : 'novels' }} on Activity: {!! $v['links']['activity'] !!}
@endif
@endif
@if (count($v['completed']) > 0)

COMPLETED
@foreach ($v['completed'] as $novel)

- {!! $novel['name'] !!}@if (!empty($novel['completed_at'])) ({{ \Illuminate\Support\Carbon::parse($novel['completed_at'])->timezone($tz)->format('d M Y') }})@endif

@if (!empty($novel['epub_url']))
  Download ePub: {!! $novel['epub_url'] !!}
@endif
@if (!empty($novel['kindle']))
  Sent to Kindle
@endif
@endforeach
@endif
@if (count($v['attention']) > 0)

NEEDS ATTENTION
@foreach ($v['attention'] as $item)

- {!! $item['name'] !!}
  {!! $item['reason'] !!}
@if (!empty($item['url']))
  Test source: {!! $item['url'] !!}
@endif
@endforeach

Health: {!! $v['links']['health'] !!}
@endif

--
Open Novarr: {!! $v['links']['home'] !!}
Activity: {!! $v['links']['activity'] !!}
Settings: {!! $v['links']['settings'] !!}
Daily at {{ $v['summary_time'] }} · change in Settings

{{-- Novels snoozed out of Needs Attention (attention_ignored_until in the
     future). Muted, one line; each name links to its novel page. --}}
@if($snoozed->isNotEmpty())
    <p class="attention-snoozed">
        <span class="attention-snoozed-key">Snoozed</span>
        @foreach($snoozed as $novel)
            <a href="{{ route('novels.show', $novel->id) }}">{{ $novel->name }}</a>
            <span class="attention-snoozed-until">until {{ \Carbon\Carbon::parse($novel->attention_ignored_until)->format('j M') }}</span>
            <form method="post" action="{{ route('novels.attention_snooze', $novel->id) }}" class="d-inline">@csrf<input type="hidden" name="days" value="0"><button type="submit" class="btn btn-link btn-sm p-0 align-baseline attention-wake" title="Show this novel in Needs attention again">Wake</button></form>@if(!$loop->last)<span aria-hidden="true"> · </span>@endif
        @endforeach
    </p>
@endif

{{-- One grab with its live status (resources/js/download-finder.js polls [data-grab-id]). --}}
@php $p = $grab->present(); @endphp
<li class="dl-grab" data-grab-id="{{ $grab->id }}" data-status="{{ $grab->status }}">
    <div class="dl-grab-body">
        <span class="dl-grab-title">{{ $grab->title }}</span>
        <span class="dl-grab-status {{ $grab->status === 'failed' ? 'dl-status-failed' : '' }} {{ $grab->status === 'imported' ? 'dl-status-done' : '' }}" data-grab-status role="status">{{ $p['status_line'] }}</span>
    </div>
    <progress class="dl-grab-bar" max="100" value="{{ (int) $grab->progress }}" data-grab-progress aria-label="Download progress" @if($grab->status !== 'downloading') hidden @endif></progress>
    <a class="btn btn-sm btn-secondary" data-grab-next href="{{ $p['next_url'] ?? '#' }}" @if(!$p['next_url']) hidden @endif>{{ $grab->kind === 'audiobook' ? 'Listen' : ($grab->kind === 'comic' ? 'Read' : 'Open') }}</a>
</li>

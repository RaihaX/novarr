{{-- "Look up on Audible" for one audiobook (AudiobookController::lookup()):
     fills its ASIN, narrator, description, series, cover and chapter names
     where the files leave them out. --}}
@if(\App\Audiobooks\AudiobookEnricher::sources() !== [])
    <form method="POST" action="{{ route('audiobooks.manage.lookup', $a->id) }}" class="ab-lookup" data-audiobook-lookup="{{ $a->id }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-ghost">Look up on Audible</button>
        @if($a->cover_path && $a->asin)
            <button type="submit" name="cover" value="1" class="btn btn-sm btn-ghost">Use Audible’s cover</button>
        @endif
    </form>
@endif

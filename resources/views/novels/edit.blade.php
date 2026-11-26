@extends('layouts.app')

@section('title', 'Edit · ' . $novel->name)

@section('content')
<div class="novel-form-column">
    <a href="{{ route('novels.show', $novel->id) }}" class="back-link">
        <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Back to {{ Str::limit($novel->name, 40) }}
    </a>

    <div class="page-head">
        <div class="page-head-titles">
            <span class="page-head-kicker">Novel #{{ $novel->id }}</span>
            <h1 class="page-title mb-0">Edit novel</h1>
        </div>
        <div class="page-head-actions">
            <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-secondary">View novel</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('novels.update', $novel->id) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="novel-form">
            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Identity</span>
                    <span class="novel-form-section-note">Fix titles here, then run Refresh Metadata</span>
                </div>
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $novel->name) }}" required>
                        <div class="form-text">Used to build NovelUpdates / NovelArrow URLs for metadata.</div>
                    </div>
                    <div class="field field-half">
                        <label for="author" class="form-label">Author</label>
                        <input type="text" name="author" id="author" class="form-control" value="{{ old('author', $novel->author) }}">
                    </div>
                    <div class="field field-quarter">
                        <label for="no_of_chapters" class="form-label">Total chapters</label>
                        <input type="number" name="no_of_chapters" id="no_of_chapters" class="form-control" value="{{ old('no_of_chapters', $novel->no_of_chapters) }}" min="0">
                    </div>
                    <div class="field field-quarter">
                        <label for="group_id" class="form-label">Group</label>
                        <select name="group_id" id="group_id" class="form-select">
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" @selected(old('group_id', $novel->group_id) == $group->id)>{{ $group->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($novel->isBook())
                        @php
                            $seriesSetBy = match ($novel->series_source) {
                                'calibre' => 'Set by Calibre',
                                'epub' => "Set by the ePub's own metadata",
                                'inferred' => 'Guessed from the titles of the author\'s books',
                                'manual' => (string) $novel->series === '' ? 'Set by you: no series (no guess will add one)' : 'Set by you',
                                default => 'No series',
                            };
                        @endphp
                        <div class="field field-half">
                            <label for="series" class="form-label">Series</label>
                            <input type="text" name="series" id="series" class="form-control" maxlength="255" value="{{ old('series', $novel->series) }}" aria-describedby="seriesSetBy">
                            <div class="form-text" id="seriesSetBy" data-series-source="{{ $novel->series_source }}">{{ $seriesSetBy }}. A change here is yours; clear it for no series.</div>
                        </div>
                        <div class="field field-quarter">
                            <label for="series_index" class="form-label">Number in series</label>
                            <input type="number" name="series_index" id="series_index" class="form-control" min="0" max="999999" step="any" value="{{ old('series_index', \App\Books\BookSeries::formatIndex($novel->series_index)) }}">
                        </div>
                    @endif
                    @php
                        // Auto unless you set it; a manual value shows as itself.
                        $isMangaForm = $novel->isManga();
                        $manualValue = match (true) {
                            ($novel->origin ?? 'unknown') === 'unknown' => 'unknown',
                            in_array($novel->origin_language, ['ja', 'ko', 'zh'], true) => $novel->origin_language,
                            $novel->origin === 'original' || $novel->origin_language === 'en' => 'en',
                            default => 'translated',
                        };
                        $originValue = old('origin', $novel->origin_source === 'manual' ? $manualValue : 'auto');
                        $originLanguage = old('origin_language', $novel->origin_language ?: 'other');
                        $originLanguages = ['vi' => 'Vietnamese', 'th' => 'Thai', 'id' => 'Indonesian', 'tl' => 'Filipino', 'es' => 'Spanish', 'other' => 'Other'];
                        $originChoices = ['ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'en' => 'EN'];
                        $detected = $novel->origin_source !== 'manual' ? ($novel->originCountryLabel() ?? $novel->originLabel()) : null;
                        $originSetBy = match ($novel->origin_source) {
                            'novelupdates' => 'Set by NovelUpdates' . ($novel->origin_type ? ' (' . $novel->origin_type . ')' : ''),
                            'inferred' => 'Inferred from the author and chapters' . ($novel->origin_confidence ? " ({$novel->origin_confidence} confidence)" : ''),
                            'source' => "Set by the series' source" . ($novel->origin_confidence ? " ({$novel->origin_confidence} confidence)" : ''),
                            'manual' => 'Set by you',
                            default => 'Not detected yet — Refresh Metadata or novel:origin fills it in',
                        } . ($detected ? " — {$detected}" : '');
                    @endphp
                    <div class="field field-half">
                        <label for="origin" class="form-label">Origin</label>
                        <select name="origin" id="origin" class="form-select" aria-describedby="originSetBy originHelp">
                            <option value="auto" @selected($originValue === 'auto')>Auto (detect)</option>
                            @foreach($originChoices as $oValue => $oCode)
                                <option value="{{ $oValue }}" @selected($originValue === $oValue || ($oValue === 'en' && $originValue === 'original'))>{{ \App\Novel::originCountryName($oCode, $isMangaForm) }}</option>
                            @endforeach
                            @unless($isMangaForm)
                                <option value="translated" @selected($originValue === 'translated')>Translated (other language)</option>
                            @endunless
                            <option value="unknown" @selected($originValue === 'unknown')>Unknown</option>
                        </select>
                        <div class="form-text" id="originSetBy" data-origin-source="{{ $novel->origin_source }}">{{ $originSetBy }}</div>
                    </div>
                    @unless($isMangaForm)
                    <div class="field field-half">
                        <label for="origin_language" class="form-label">Other original language</label>
                        <select name="origin_language" id="origin_language" class="form-select">
                            @foreach($originLanguages as $code => $label)
                                <option value="{{ $code }}" @selected($originLanguage === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" id="originHelp">Used with "Translated (other language)". Any choice but Auto is yours — automatic detection won't overwrite it. Pick Auto to hand it back.</div>
                    </div>
                    @else
                    <div class="field field-half">
                        <div class="form-text" id="originHelp">Any choice but Auto is yours — automatic detection won't overwrite it. Pick Auto to hand it back.</div>
                    </div>
                    @endunless
                    <div class="field field-full">
                        <label for="description" class="form-label">Synopsis</label>
                        <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $novel->description) }}</textarea>
                    </div>
                </div>
            </div>

            {{-- An uploaded book has no source to scrape: its identity is its file. --}}
            @unless($novel->isBook())
            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Sources</span>
                    <span class="novel-form-section-note">Where the scraper reads from</span>
                </div>
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="translator_url" class="form-label">Source URL <span class="field-hint">translator_url — used for TOC scraping</span></label>
                        <input type="url" name="translator_url" id="translator_url" class="form-control" value="{{ old('translator_url', $novel->translator_url) }}">
                    </div>
                    <div class="field field-full">
                        <label for="novelupdates_url" class="form-label">NovelUpdates URL <span class="field-hint">optional — overrides metadata lookup for aliased titles</span></label>
                        <input type="url" name="novelupdates_url" id="novelupdates_url" class="form-control" value="{{ old('novelupdates_url', $novel->novelupdates_url) }}" placeholder="https://www.novelupdates.com/series/…">
                        <div class="form-text">Set this when the title differs from NovelUpdates. Leave blank to auto-resolve, then run Refresh Metadata.</div>
                    </div>
                    <div class="field field-full" id="nuMatch" data-candidates-url="{{ route('novels.metadata_candidates', $novel->id) }}" data-choose-url="{{ route('novels.metadata_choose', $novel->id) }}">
                        @php
                            $nuScore = $novel->novelupdates_match_score;
                            $nuThreshold = \App\Scraping\NovelUpdatesMatcher::threshold();
                        @endphp
                        <label class="form-label">NovelUpdates match <span class="field-hint">completion is only trusted at a score of {{ number_format($nuThreshold, 2) }}+</span></label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            @if($novel->novelupdates_url)
                                <a href="{{ $novel->novelupdates_url }}" target="_blank" rel="noopener" class="mono">{{ Str::after($novel->novelupdates_url, 'novelupdates.com') }}</a>
                            @else
                                <span class="text-muted">No series matched yet</span>
                            @endif
                            @if($nuScore === null)
                                <x-status state="paused">unscored</x-status>
                            @elseif((float) $nuScore >= $nuThreshold)
                                <span class="badge badge-success">score {{ number_format((float) $nuScore, 3) }}</span>
                            @else
                                <span class="badge badge-warning">score {{ number_format((float) $nuScore, 3) }}</span>
                            @endif
                            <button type="button" class="btn btn-secondary btn-sm" data-nu-find>Find candidates</button>
                        </div>
                        <ul class="list-unstyled mt-2 mb-0 d-none" data-nu-list></ul>
                    </div>
                    <div class="field field-half">
                        <label for="chapter_url" class="form-label">Chapter URL base</label>
                        <input type="url" name="chapter_url" id="chapter_url" class="form-control" value="{{ old('chapter_url', $novel->chapter_url) }}">
                    </div>
                    <div class="field field-half">
                        <label for="alternative_url" class="form-label">Alternative URL base</label>
                        <input type="url" name="alternative_url" id="alternative_url" class="form-control" value="{{ old('alternative_url', $novel->alternative_url) }}">
                    </div>
                </div>
            </div>
            @endunless

            <div class="novel-form-section">
                <div class="novel-form-section-head">
                    <span class="novel-form-section-title">Presentation</span>
                    <span class="novel-form-section-note">Tags and cover art</span>
                </div>
                <div class="form-grid">
                    <div class="field field-half">
                        <label class="form-label">Tags</label>
                        @include('partials.tag-picker', ['selectedIds' => $novel->tags->pluck('id')->all()])
                    </div>
                    <div class="field field-half">
                        <label for="image" class="form-label">Replace cover image <span class="field-hint">optional</span></label>
                        <input type="file" name="image" id="image" class="form-control" accept="image/*">
                    </div>
                </div>
            </div>

            <div class="novel-form-actions">
                <button type="submit" class="btn btn-primary">Save changes</button>
                <a href="{{ route('novels.show', $novel->id) }}" class="btn btn-secondary">Cancel</a>
                <span class="novel-form-actions-note">ID {{ $novel->id }}</span>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
(() => {
    // NovelUpdates match: list scored search candidates, pick one by hand.
    const box = document.getElementById('nuMatch');
    if (!box) return;

    const list = box.querySelector('[data-nu-list]');
    const findBtn = box.querySelector('[data-nu-find]');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const row = (c, threshold) => {
        const li = document.createElement('li');
        li.className = 'd-flex flex-wrap align-items-center gap-2 py-1';

        const badge = document.createElement('span');
        badge.className = 'badge ' + (c.score >= threshold ? 'badge-success' : 'badge-muted');
        badge.textContent = c.score.toFixed(3);

        const link = document.createElement('a');
        link.href = c.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = c.title || c.url;

        const use = document.createElement('button');
        use.type = 'button';
        use.className = 'btn btn-ghost btn-sm';
        use.textContent = 'Use this';
        use.addEventListener('click', () => choose(c, use));

        li.append(badge, link, use);
        return li;
    };

    async function choose(candidate, btn) {
        const ok = await Novarr.confirmDialog(
            `Use "${candidate.title || candidate.url}" as this novel's NovelUpdates series? Metadata will be refreshed from it.`,
            { title: 'Use this match', confirmText: 'Use this' }
        );
        if (!ok) return;

        btn.disabled = true;
        try {
            const response = await fetch(box.dataset.chooseUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: candidate.url }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Could not save the match.');

            Novarr.showToast(data.message, 'success');
            const urlInput = document.getElementById('novelupdates_url');
            if (urlInput) urlInput.value = data.url;
            Novarr.softRefresh(800);
        } catch (err) {
            Novarr.showToast(err.message, 'danger');
            btn.disabled = false;
        }
    }

    findBtn.addEventListener('click', async () => {
        findBtn.disabled = true;
        const label = findBtn.textContent;
        findBtn.textContent = 'Searching…';
        list.replaceChildren();
        try {
            const response = await fetch(box.dataset.candidatesUrl, { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Search failed.');

            if (!data.candidates.length) {
                const li = document.createElement('li');
                li.className = 'text-muted';
                li.textContent = 'No NovelUpdates results for this name — paste the series URL above instead.';
                list.append(li);
            } else {
                data.candidates.forEach(c => list.append(row(c, data.threshold)));
            }
            list.classList.remove('d-none');
        } catch (err) {
            Novarr.showToast(err.message, 'danger');
        } finally {
            findBtn.disabled = false;
            findBtn.textContent = label;
        }
    });
})();
</script>
@endpush

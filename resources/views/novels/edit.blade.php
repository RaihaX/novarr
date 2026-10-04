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
                    @php
                        $originValue = old('origin', $novel->isTranslated() === null ? 'unknown' : $novel->origin);
                        $originLanguage = old('origin_language', $novel->origin_language ?: ($novel->origin === 'translated' ? 'other' : 'en'));
                        $originLanguages = ['en' => 'English', 'zh' => 'Chinese', 'ko' => 'Korean', 'ja' => 'Japanese', 'vi' => 'Vietnamese', 'th' => 'Thai', 'id' => 'Indonesian', 'other' => 'Other'];
                        $originSetBy = match ($novel->origin_source) {
                            'novelupdates' => 'Set by NovelUpdates' . ($novel->origin_type ? ' (' . $novel->origin_type . ')' : ''),
                            'inferred' => 'Inferred from the author and chapters',
                            'manual' => 'Set by you',
                            default => 'Not detected yet — Refresh Metadata or novel:origin fills it in',
                        };
                    @endphp
                    <div class="field field-half">
                        <label for="origin" class="form-label">Origin</label>
                        <select name="origin" id="origin" class="form-select" aria-describedby="originSetBy">
                            <option value="unknown" @selected($originValue === 'unknown')>Unknown</option>
                            <option value="original" @selected($originValue === 'original')>Original (English)</option>
                            <option value="translated" @selected($originValue === 'translated')>Translated</option>
                        </select>
                        <div class="form-text" id="originSetBy" data-origin-source="{{ $novel->origin_source }}">{{ $originSetBy }}</div>
                    </div>
                    <div class="field field-half">
                        <label for="origin_language" class="form-label">Original language</label>
                        <select name="origin_language" id="origin_language" class="form-select">
                            @foreach($originLanguages as $code => $label)
                                <option value="{{ $code }}" @selected($originLanguage === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Changing either field makes it yours — automatic detection won't overwrite it. Pick Unknown to hand it back.</div>
                    </div>
                    <div class="field field-full">
                        <label for="description" class="form-label">Synopsis</label>
                        <textarea name="description" id="description" class="form-control" rows="5">{{ old('description', $novel->description) }}</textarea>
                    </div>
                </div>
            </div>

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

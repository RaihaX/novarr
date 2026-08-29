@extends('layouts.app')

@section('content')
<a href="{{ route('novels.index') }}" class="back-link">
    <x-icon name="chevron-left" :size="14" :stroke="1.5" /> Novels
</a>

{{-- ===================================================================== --}}
{{-- Hero — cover, identity, actions, meters                               --}}
{{-- ===================================================================== --}}
<div class="detail-hero">
    <div>
        @if($data->file)
            <img src="{{ Storage::url($data->file->file_path) }}" alt="Cover of {{ $data->name }}" class="detail-cover">
        @else
            <div class="detail-cover-placeholder" aria-hidden="true">
                <x-brand-mark variant="mono" :size="34" />
            </div>
        @endif
    </div>

    <div class="detail-main">
        <div class="detail-head">
            <div class="detail-ident">
                <h1 class="detail-title">{{ $data->name }}</h1>

                <div class="detail-byline">
                    <span class="detail-author">{{ $data->author ?: 'Unknown author' }}</span>
                    @if($data->status)
                        <span id="novelStatusBadge" class="badge badge-completed" data-completed="1">Completed</span>
                    @elseif($data->paused_at)
                        <span id="novelStatusBadge" class="badge badge-paused" title="Paused {{ $data->paused_at->format('j M Y') }} — automatic downloads skip this novel">Paused</span>
                    @else
                        <span id="novelStatusBadge" class="badge badge-active">Active</span>
                    @endif
                </div>

                @php
                    $metaItems = [];
                    if ($data->group && $data->group->label) $metaItems[] = ['key' => 'Group', 'value' => $data->group->label];
                    if ($data->language && $data->language->label) $metaItems[] = ['key' => 'Lang', 'value' => $data->language->label];
                @endphp
                @if($data->translator_url || count($metaItems))
                    <div class="detail-meta">
                        @if($data->translator_url)
                            <a class="detail-meta-item" href="{{ $data->translator_url }}" target="_blank" rel="noopener">
                                <span class="detail-meta-key">Source</span>{{ parse_url($data->translator_url, PHP_URL_HOST) }} &nearr;
                            </a>
                            @if(count($metaItems))<span class="detail-meta-sep">·</span>@endif
                        @endif
                        @foreach($metaItems as $i => $item)
                            <span class="detail-meta-item"><span class="detail-meta-key">{{ $item['key'] }}</span>{{ $item['value'] }}</span>
                            @if($i < count($metaItems) - 1)<span class="detail-meta-sep">·</span>@endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="detail-actions">
                @if($continue_chapter_id)
                    <a href="{{ route('chapters.show', $continue_chapter_id) }}" class="btn btn-primary">{{ $read_count > 0 ? 'Continue reading' : 'Start reading' }}</a>
                @endif
                <span id="offlineControls" data-id="{{ $data->id }}" data-total="{{ $current_chapters }}" data-unread="{{ max(0, $current_chapters - $read_count) }}" class="d-inline-flex gap-2">
                    <div class="dropdown">
                        <button type="button" id="offlineBtn" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">Download for offline</button>
                        <ul class="dropdown-menu dropdown-menu-end" style="min-width: 260px;">
                            <li><button type="button" class="dropdown-item" data-scope="unread-next" data-limit="100">Next 100 unread</button></li>
                            <li><button type="button" class="dropdown-item" data-scope="unread">All unread (<span class="offl-unread">0</span>)</button></li>
                            <li><button type="button" class="dropdown-item" data-scope="all">All chapters (<span class="offl-total">0</span>)</button></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <div class="px-2 pt-1 pb-1">
                                    <div class="label-caption mb-2">Chapter range</div>
                                    <div class="d-flex gap-1 align-items-center">
                                        <input type="number" id="offlFrom" class="form-control form-control-sm" placeholder="From" min="0" step="any" style="width: 78px;" aria-label="Range from chapter">
                                        <input type="number" id="offlTo" class="form-control form-control-sm" placeholder="To" min="0" step="any" style="width: 78px;" aria-label="Range to chapter">
                                        <button type="button" class="btn btn-sm btn-secondary" data-scope="range">Get</button>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <button type="button" id="offlineRemove" class="btn btn-secondary d-none">Remove offline</button>
                </span>
                <a href="{{ route('novels.edit', $data->id) }}" class="btn btn-secondary">Edit</a>
                <button type="button" id="pauseToggle" class="btn {{ $data->paused_at ? 'btn-success' : 'btn-secondary' }}" data-id="{{ $data->id }}" title="Paused novels are skipped by automatic downloads; manual commands still work">
                    {{ $data->paused_at ? 'Resume downloads' : 'Pause downloads' }}
                </button>
                @if(!$data->status)
                    <button type="button" id="frequentToggle" class="btn {{ $data->frequent_toc ? 'btn-info' : 'btn-secondary' }}" data-id="{{ $data->id }}" title="Check this novel's source for new chapters every hour instead of once a day">
                        {{ $data->frequent_toc ? 'Hourly checks on' : 'Hourly checks off' }}
                    </button>
                @endif
                <button type="button" id="deleteNovel" class="btn btn-danger" data-id="{{ $data->id }}" data-name="{{ $data->name }}">Delete</button>
            </div>
        </div>

        {{-- Metric strip --}}
        <div class="metric-strip">
            <div class="metric">
                <div class="metric-value text-success">{{ number_format($current_chapters) }}</div>
                <div class="metric-label">Downloaded</div>
            </div>
            <div class="metric">
                <div class="metric-value {{ $current_chapters_not_downloaded > 0 ? 'value-pending' : 'value-muted' }}">{{ number_format($current_chapters_not_downloaded) }}</div>
                <div class="metric-label">Queued</div>
            </div>
            <div class="metric">
                <div class="metric-value {{ count($missing_chapters) > 0 ? 'text-danger' : 'value-muted' }}">{{ number_format(count($missing_chapters)) }}</div>
                <div class="metric-label">Missing</div>
            </div>
            <div class="metric">
                <div class="metric-value text-amber">{{ number_format($read_count) }}</div>
                <div class="metric-label">Read</div>
            </div>
        </div>

        {{-- Meters: download progress (accent), reading progress (always amber) --}}
        <div>
            <div class="meter">
                <div class="meter-head">
                    <span class="meter-label">Library progress</span>
                    <span class="meter-value">{{ $progress }}%</span>
                </div>
                <div class="progress {{ $progress >= 100 ? 'progress-downloaded' : '' }}" role="progressbar" aria-label="Download progress" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: {{ $progress }}%"></div>
                </div>
            </div>

            @if($current_chapters > 0)
                @php $readPct = (int) round($read_count / $current_chapters * 100); @endphp
                <div class="meter meter-reading">
                    <div class="meter-head">
                        <span class="meter-label">Read</span>
                        <span class="meter-value">{{ number_format($read_count) }} / {{ number_format($current_chapters) }} · {{ $readPct }}%</span>
                    </div>
                    <div class="progress progress-reading" role="progressbar" aria-label="Reading progress" aria-valuenow="{{ $readPct }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: {{ $readPct }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Tags --}}
        <div>
            <div id="tagDisplay" class="tag-strip">
                <span class="tag-strip-key">Tags</span>
                <span id="tagList" class="d-flex align-items-center gap-2 flex-wrap">
                    @forelse($data->tags as $tag)
                        <a href="{{ route('novels.index', ['tag' => $tag->id]) }}" class="tag-chip">{{ $tag->name }}</a>
                    @empty
                        <span class="tag-empty">None</span>
                    @endforelse
                </span>
                <button type="button" id="editTags" class="btn btn-ghost btn-sm">Edit</button>
            </div>
            <div id="tagEditor" class="d-none">
                <div class="label-caption mb-2">Tags</div>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    @include('partials.tag-picker', ['selectedIds' => $data->tags->pluck('id')->all()])
                    <button type="button" id="saveTags" class="btn btn-primary btn-sm" data-id="{{ $data->id }}">Save</button>
                    <button type="button" id="cancelTags" class="btn btn-secondary btn-sm">Cancel</button>
                </div>
            </div>
        </div>

        {{-- Synopsis --}}
        @if($synopsis)
            <div class="detail-synopsis" id="synopsis">
                <div class="synopsis-body" id="synopsisBody">{!! $synopsis !!}</div>
                <button type="button" class="synopsis-toggle d-none" id="synopsisToggle" aria-expanded="false">Read more</button>
            </div>
        @else
            <div class="detail-no-synopsis">
                <em>No summary available.</em>
                <button class="btn btn-secondary btn-sm cmd-btn" data-command="metadata" data-novel="{{ $data->id }}">
                    <span class="cmd-label">Refresh metadata</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        @endif
    </div>
</div>

{{-- ===================================================================== --}}
{{-- Quick actions                                                          --}}
{{-- ===================================================================== --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Quick actions</h2>
        <span class="panel-note">Commands run in the background</span>
    </div>
    <div class="card-body">
        <div class="qa-section">
            <div class="qa-label">Acquire</div>
            <div class="qa-buttons">
                <button class="btn btn-primary cmd-btn" data-command="toc" data-novel="{{ $data->id }}" title="Re-scrape the table of contents to discover new chapters">
                    <span class="cmd-label">Scrape TOC</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-primary cmd-btn" data-command="chapter" data-novel="{{ $data->id }}" title="Download the content of any pending chapters">
                    <span class="cmd-label">Download chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>

        <div class="qa-section">
            <div class="qa-label">Export</div>
            <div class="qa-buttons">
                <button class="btn btn-secondary cmd-btn" data-command="epub" data-novel="{{ $data->id }}" title="Build an ePub from the downloaded chapters">
                    <span class="cmd-label">Generate ePub</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <a href="{{ route('novels.download_epub', $data->id) }}" class="btn btn-secondary cmd-btn">Download ePub</a>
                <button class="btn btn-secondary cmd-btn" data-command="send_to_kindle" data-novel="{{ $data->id }}" title="Email this novel's ePub to your Kindle">
                    <span class="cmd-label">Send to Kindle</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Sending</span>
                    <span class="cmd-done d-none">Sent</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>

        <div class="qa-section">
            <div class="qa-label">Maintenance</div>
            <div class="qa-buttons">
                <button class="btn btn-secondary cmd-btn" data-command="metadata" data-novel="{{ $data->id }}" title="Re-fetch title, author, cover and synopsis from the source">
                    <span class="cmd-label">Refresh metadata</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="normalize_labels" data-novel="{{ $data->id }}" title="Rewrite chapter labels/numbers to a consistent format">
                    <span class="cmd-label">Normalize labels</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="fix_chapters" data-novel="{{ $data->id }}" title="Resolve chapters with missing numbers by elimination against the novel sequence">
                    <span class="cmd-label">Fix chapter numbers</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="clean_content" data-novel="{{ $data->id }}" title="Strip leftover CSS and ad-widget text from downloaded chapters">
                    <span class="cmd-label">Clean formatting</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
                <button class="btn btn-secondary cmd-btn" data-command="chapter_cleaner" data-novel="{{ $data->id }}" title="Re-download chapters that saved with little or no content">
                    <span class="cmd-label">Fix empty chapters</span>
                    <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Running</span>
                    <span class="cmd-done d-none">Done</span>
                    <span class="cmd-fail d-none">Failed</span>
                </button>
            </div>
        </div>
    </div>
    <div id="cmdOutput" class="d-none">
        <pre id="cmdOutputText" class="cmd-output-pane"></pre>
    </div>
</div>

{{-- ===================================================================== --}}
{{-- Chapters                                                               --}}
{{-- ===================================================================== --}}
<div class="card">
    <div class="panel-head">
        <h2 class="panel-title">Chapters <span class="count-chip">{{ number_format($chapters->total()) }}</span></h2>
        <div class="panel-tools">
            <div id="chBulkBar" class="bulk-bar">
                <span id="chBulkCount" class="bulk-count"></span>
                <button type="button" id="chMarkRead" class="btn btn-ghost btn-sm">Mark read</button>
                <button type="button" id="chMarkUnread" class="btn btn-ghost btn-sm">Mark unread</button>
            </div>
            <div class="dropdown">
                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Mark read</button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header label-caption">Needs one chapter selected</h6></li>
                    <li><button type="button" class="dropdown-item" id="chReadUpTo">Read up to selected chapter</button></li>
                    <li><button type="button" class="dropdown-item" id="chReadFrom">Read from selected chapter to end</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item" id="chReadAll">Mark all chapters read</button></li>
                    <li><button type="button" class="dropdown-item text-danger" id="chUnreadAll">Mark all chapters unread</button></li>
                </ul>
            </div>
            @if(count($duplicate_chapters) > 0)
                <button type="button" id="removeDupes" class="btn btn-warning btn-sm" data-id="{{ $data->id }}" title="{{ count($duplicate_chapters) }} duplicate chapter group(s) detected">Remove {{ count($duplicate_chapters) }} duplicate(s)</button>
            @endif
            <form method="GET" action="{{ route('novels.jump_chapter', $data->id) }}" class="d-flex gap-1">
                <input type="number" name="n" step="any" min="0" class="form-control form-control-sm" style="width: 84px;" placeholder="Ch. #" aria-label="Jump to chapter">
                <button type="submit" class="btn btn-secondary btn-sm">Go</button>
            </form>
            <form method="GET" action="{{ route('search.index') }}" class="d-flex gap-1">
                <input type="hidden" name="novel" value="{{ $data->id }}">
                <input type="search" name="q" minlength="2" class="form-control form-control-sm" style="width: 150px;" placeholder="Search in novel…" aria-label="Search within this novel">
                <button type="submit" class="btn btn-secondary btn-sm">Find</button>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover chapter-table align-middle">
            <thead>
                <tr>
                    <th style="width: 46px"><input type="checkbox" id="chSelectAll" class="form-check-input" aria-label="Select all chapters"></th>
                    <th style="width: 84px">Ch.</th>
                    <th style="width: 60px">Book</th>
                    <th>Title</th>
                    <th style="width: 118px">Status</th>
                    <th style="width: 150px">Downloaded</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chapters as $chapter)
                    <tr class="chapter-row {{ $chapter->status ? 'is-downloaded' : 'is-queued' }}">
                        <td><input type="checkbox" class="form-check-input ch-check" value="{{ $chapter->id }}" aria-label="Select chapter {{ $chapter->chapter }}"></td>
                        <td class="ch-num">{{ $chapter->chapter }}</td>
                        <td class="ch-book">{{ $chapter->book ?: '—' }}</td>
                        <td>
                            @if($chapter->read_at)
                                <span class="read-check" title="Read {{ $chapter->read_at->format('Y-m-d H:i') }}">✓</span>
                            @endif
                            @if($chapter->status)
                                <a href="{{ route('chapters.show', $chapter->id) }}" class="chapter-link {{ $chapter->read_at ? 'text-muted' : '' }}">{{ Str::limit($chapter->label, 90) }}</a>
                            @else
                                <span class="text-muted">{{ Str::limit($chapter->label, 90) }}</span>
                            @endif
                        </td>
                        <td>
                            @if($chapter->status)
                                <span class="badge badge-downloaded">Downloaded</span>
                            @else
                                <span class="badge badge-queued">Queued</span>
                            @endif
                        </td>
                        <td class="ch-date">{{ $chapter->download_date ? $chapter->download_date->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No chapters found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($chapters->hasPages())
        <div class="card-footer">
            {{ $chapters->links() }}
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    // Synopsis read-more: only show the toggle when the text actually clamps.
    const synopsisBody = document.getElementById('synopsisBody');
    const synopsisToggle = document.getElementById('synopsisToggle');

    if (synopsisBody && synopsisToggle) {
        if (synopsisBody.scrollHeight > synopsisBody.clientHeight + 2) {
            synopsisToggle.classList.remove('d-none');
        }

        synopsisToggle.addEventListener('click', () => {
            const expanded = synopsisBody.classList.toggle('expanded');
            synopsisToggle.textContent = expanded ? 'Read less' : 'Read more';
            synopsisToggle.setAttribute('aria-expanded', expanded);
        });
    }

    document.querySelectorAll('button.cmd-btn').forEach(btn => {
        btn.addEventListener('click', () => runCommand(btn));
    });

    const deleteBtn = document.getElementById('deleteNovel');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                `Delete "${deleteBtn.dataset.name}" and all of its chapters? This cannot be undone from the UI.`,
                { title: 'Delete novel', confirmText: 'Delete', danger: true }
            );
            if (!ok) return;
            deleteBtn.disabled = true;
            try {
                const response = await fetch(`/novels/${deleteBtn.dataset.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = '{{ route('novels.index') }}';
                } else {
                    deleteBtn.disabled = false;
                    Novarr.showToast('Failed to delete novel.', 'danger');
                }
            } catch (err) {
                deleteBtn.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    const pauseToggle = document.getElementById('pauseToggle');
    if (pauseToggle) {
        pauseToggle.addEventListener('click', async () => {
            pauseToggle.disabled = true;
            try {
                const response = await fetch(`/novels/${pauseToggle.dataset.id}/toggle-pause`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    // Update the button + status badge in place (no reload).
                    pauseToggle.className = 'btn ' + (data.paused ? 'btn-success' : 'btn-secondary');
                    pauseToggle.textContent = data.paused ? 'Resume downloads' : 'Pause downloads';

                    const badge = document.getElementById('novelStatusBadge');
                    if (badge && !badge.dataset.completed) {
                        badge.className = 'badge ' + (data.paused ? 'badge-paused' : 'badge-active');
                        badge.textContent = data.paused ? 'Paused' : 'Active';
                    }
                    Novarr.showToast(data.paused ? 'Downloads paused.' : 'Downloads resumed.', 'success');
                } else {
                    Novarr.showToast('Failed to update pause state.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                pauseToggle.disabled = false;
            }
        });
    }

    const frequentToggle = document.getElementById('frequentToggle');
    if (frequentToggle) {
        frequentToggle.addEventListener('click', async () => {
            frequentToggle.disabled = true;
            try {
                const response = await fetch(`/novels/${frequentToggle.dataset.id}/toggle-frequent`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    frequentToggle.className = 'btn ' + (data.frequent ? 'btn-info' : 'btn-secondary');
                    frequentToggle.textContent = data.frequent ? 'Hourly checks on' : 'Hourly checks off';
                    Novarr.showToast(data.frequent ? 'This novel is now checked hourly for new chapters.' : 'Back to the daily check.', 'success');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                frequentToggle.disabled = false;
            }
        });
    }

    // ---- Tag editing ----
    const editTags = document.getElementById('editTags');
    if (editTags) {
        const tagDisplay = document.getElementById('tagDisplay');
        const tagEditor = document.getElementById('tagEditor');
        const showEditor = (on) => {
            tagDisplay.classList.toggle('d-none', on);
            tagEditor.classList.toggle('d-none', !on);
            if (on) tagEditor.querySelector('.tag-picker-toggle')?.focus();
        };
        editTags.addEventListener('click', () => showEditor(true));
        document.getElementById('cancelTags').addEventListener('click', () => showEditor(false));

        // Rebuild the tag chips in place from the saved tag list.
        const tagBase = '{{ route('novels.index') }}';
        function renderTags(tags) {
            const list = document.getElementById('tagList');
            list.innerHTML = '';
            if (!tags || !tags.length) {
                const none = document.createElement('span');
                none.className = 'tag-empty';
                none.textContent = 'None';
                list.appendChild(none);
                return;
            }
            tags.forEach(t => {
                const a = document.createElement('a');
                a.href = `${tagBase}?tag=${t.id}`;
                a.className = 'tag-chip';
                a.textContent = t.name;
                list.appendChild(a);
            });
        }

        document.getElementById('saveTags').addEventListener('click', async (e) => {
            const btn = e.target;
            btn.disabled = true;
            try {
                const tagIds = [...document.querySelectorAll('#tagEditor input[name="tags[]"]:checked')].map(c => c.value);
                const response = await fetch(`/novels/${btn.dataset.id}/tags`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ tags: tagIds }),
                });
                const data = await response.json();
                if (data.success) {
                    renderTags(data.tags);
                    showEditor(false);
                    Novarr.showToast('Tags saved.', 'success');
                } else {
                    Novarr.showToast('Failed to save tags.', 'danger');
                }
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- Remove duplicate chapters ----
    const removeDupes = document.getElementById('removeDupes');
    if (removeDupes) {
        removeDupes.addEventListener('click', async () => {
            const ok = await Novarr.confirmDialog(
                'Remove duplicate chapters, keeping the best copy of each?',
                { title: 'Remove duplicates', confirmText: 'Remove' }
            );
            if (!ok) return;
            removeDupes.disabled = true;
            try {
                const response = await fetch(`/novels/${removeDupes.dataset.id}/remove-duplicates`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await response.json();
                if (data.success) {
                    Novarr.showToast(`Removed ${data.removed} duplicate chapter(s).`, 'success');
                    Novarr.softRefresh(1000);
                } else {
                    removeDupes.disabled = false;
                    Novarr.showToast('Failed to remove duplicates.', 'danger');
                }
            } catch (err) {
                removeDupes.disabled = false;
                Novarr.showToast('Error: ' + err.message, 'danger');
            }
        });
    }

    // ---- Chapter bulk read/unread ----
    const chChecks = () => [...document.querySelectorAll('.ch-check')];
    const chSelected = () => chChecks().filter(c => c.checked).map(c => c.value);
    const chBulkBar = document.getElementById('chBulkBar');
    const chSelectAll = document.getElementById('chSelectAll');

    function refreshChBulk() {
        const n = chSelected().length;
        chBulkBar.classList.toggle('d-none', n === 0);
        chBulkBar.classList.toggle('d-flex', n > 0);
        document.getElementById('chBulkCount').textContent = `${n} selected`;
        if (chSelectAll) {
            chSelectAll.checked = n > 0 && n === chChecks().length;
            chSelectAll.indeterminate = n > 0 && n < chChecks().length;
        }
    }

    chChecks().forEach(c => c.addEventListener('change', refreshChBulk));
    chSelectAll?.addEventListener('change', () => {
        chChecks().forEach(c => c.checked = chSelectAll.checked);
        refreshChBulk();
    });

    // Toggle a chapter row's read indicator (amber ✓ + muted title) in place,
    // so the long paginated table keeps its scroll position after a bulk action.
    function setChapterRowRead(checkbox, read) {
        const cell = checkbox.closest('tr')?.querySelector('td:nth-child(4)');
        if (!cell) return;

        let mark = cell.querySelector('.read-check');
        if (read && !mark) {
            mark = document.createElement('span');
            mark.className = 'read-check';
            mark.title = 'Read';
            mark.textContent = '✓';
            cell.insertBefore(mark, cell.firstChild);
        } else if (!read && mark) {
            mark.remove();
        }
        cell.querySelector('.chapter-link')?.classList.toggle('text-muted', read);
    }

    async function bulkRead(read) {
        const boxes = chChecks().filter(c => c.checked);
        const ids = boxes.map(c => c.value);
        if (!ids.length) return;
        try {
            const response = await fetch('{{ route('chapters.bulk_read') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ids, read }),
            });
            const data = await response.json();
            if (data.success) {
                boxes.forEach(c => { setChapterRowRead(c, read); c.checked = false; });
                refreshChBulk();
                Novarr.showToast(
                    data.queued
                        ? 'Saved offline — will sync when you reconnect.'
                        : `Marked ${ids.length} chapter(s) as ${read ? 'read' : 'unread'}.`,
                    data.queued ? 'info' : 'success'
                );
            } else {
                Novarr.showToast(data.message || 'Failed to update chapters.', 'danger');
            }
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('chMarkRead')?.addEventListener('click', () => bulkRead(true));
    document.getElementById('chMarkUnread')?.addEventListener('click', () => bulkRead(false));

    // Scoped variants: whole novel, or everything up to / from an anchor
    // chapter. Server updates all pages; the DOM update below only needs to
    // cover the rows visible on this page.
    async function bulkReadScope(scope, read) {
        let anchorId = null;
        if (scope === 'up_to' || scope === 'from') {
            const sel = chSelected();
            if (sel.length !== 1) {
                Novarr.showToast('Select exactly one chapter as the starting point first.', 'warning');
                return;
            }
            anchorId = parseInt(sel[0], 10);
        }

        if (scope === 'all') {
            const ok = await Novarr.confirmDialog(
                `Mark ALL chapters as ${read ? 'read' : 'unread'}?`,
                { title: read ? 'Mark all read' : 'Mark all unread', confirmText: 'Continue', danger: !read }
            );
            if (!ok) return;
        }

        try {
            const response = await fetch('{{ route('chapters.bulk_read') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ read, scope, novel_id: {{ $data->id }}, anchor_id: anchorId }),
            });
            const data = await response.json();
            if (!data.success) {
                Novarr.showToast(data.message || 'Failed to update chapters.', 'danger');
                return;
            }

            const boxes = chChecks();
            let affected = boxes;
            if (anchorId !== null) {
                const idx = boxes.findIndex(c => parseInt(c.value, 10) === anchorId);
                affected = scope === 'up_to' ? boxes.slice(0, idx + 1) : boxes.slice(idx);
            }
            affected.forEach(c => setChapterRowRead(c, read));
            boxes.forEach(c => c.checked = false);
            refreshChBulk();
            Novarr.showToast(
                data.queued
                    ? 'Saved offline — will sync when you reconnect.'
                    : `Marked ${data.count} chapter(s) as ${read ? 'read' : 'unread'}.`,
                data.queued ? 'info' : 'success'
            );
        } catch (err) {
            Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    document.getElementById('chReadUpTo')?.addEventListener('click', () => bulkReadScope('up_to', true));
    document.getElementById('chReadFrom')?.addEventListener('click', () => bulkReadScope('from', true));
    document.getElementById('chReadAll')?.addEventListener('click', () => bulkReadScope('all', true));
    document.getElementById('chUnreadAll')?.addEventListener('click', () => bulkReadScope('all', false));

    // ---- Download for offline (PWA), with range options ----
    function initOfflineBtn() {
        const wrap = document.getElementById('offlineControls');
        if (!wrap || !window.Novarr?.downloadNovel) return;

        const id = parseInt(wrap.dataset.id, 10);
        const btn = document.getElementById('offlineBtn');
        const removeBtn = document.getElementById('offlineRemove');

        // Fill the option counts from the page's stats.
        wrap.querySelectorAll('.offl-total').forEach(e => e.textContent = wrap.dataset.total || '0');
        wrap.querySelectorAll('.offl-unread').forEach(e => e.textContent = wrap.dataset.unread || '0');

        async function reflect() {
            const rec = await Novarr.getNovel(id);
            btn.textContent = rec ? `${rec.chapterCount} offline` : 'Download for offline';
            btn.classList.toggle('btn-info', !!rec);
            btn.classList.toggle('btn-secondary', !rec);
            removeBtn.classList.toggle('d-none', !rec);
        }
        reflect();

        function closeMenu() {
            window.bootstrap?.Dropdown.getOrCreateInstance(btn).hide();
        }

        async function run(opts) {
            closeMenu();
            btn.disabled = true;
            btn.classList.add('disabled');
            try {
                const r = await Novarr.downloadNovel(id, opts, (done, total) => {
                    btn.textContent = `Saving ${done}/${total}…`;
                });
                Novarr.showToast(`Saved ${r.addedCount} chapter(s) for offline (${r.cachedCount} total).`, 'success');
            } catch (err) {
                Novarr.showToast('Download failed: ' + err.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.classList.remove('disabled');
                reflect();
            }
        }

        wrap.querySelectorAll('[data-scope]').forEach(el => el.addEventListener('click', () => {
            const scope = el.dataset.scope;
            if (scope === 'range') {
                const from = document.getElementById('offlFrom').value.trim();
                const to = document.getElementById('offlTo').value.trim();
                if (!from && !to) {
                    Novarr.showToast('Enter a “from” and/or “to” chapter number.', 'warning');
                    return;
                }
                run({ scope: 'range', from, to });
            } else if (scope === 'unread-next') {
                run({ scope: 'unread-next', limit: parseInt(el.dataset.limit, 10) || 100 });
            } else {
                run({ scope });
            }
        }));

        removeBtn.addEventListener('click', async () => {
            removeBtn.disabled = true;
            try {
                await Novarr.removeNovel(id);
                Novarr.showToast('Removed offline copy.', 'info');
            } catch (err) {
                Novarr.showToast('Error: ' + err.message, 'danger');
            } finally {
                removeBtn.disabled = false;
                reflect();
            }
        });
    }

    // window.Novarr is set by the deferred app.js module, which runs after this
    // inline script on a hard load but is already present on Turbo visits.
    if (window.Novarr?.downloadNovel) initOfflineBtn();
    else window.addEventListener('load', initOfflineBtn, { once: true });

    async function runCommand(btn) {
        if (btn.disabled) return;

        const command = btn.dataset.command;
        const novelId = btn.dataset.novel;
        const outputText = document.getElementById('cmdOutputText');

        setButtonState(btn, 'running');
        document.getElementById('cmdOutput').classList.remove('d-none');
        outputText.textContent = `> ${command} --novel=${novelId}\nRunning...`;

        // Commands that change the chapter list or stats shown on this page —
        // reload after they finish so the page reflects the new data.
        const reloadAfter = ['toc', 'chapter', 'metadata', 'normalize_labels', 'fix_chapters', 'clean_content', 'chapter_cleaner'];

        try {
            const result = await Novarr.executeCommand({ command, novel_id: novelId });
            setButtonState(btn, result.success ? 'done' : 'fail');
            outputText.textContent = `> ${command} --novel=${novelId}\n${result.output || result.error || 'Done'}`;
            outputText.scrollTop = outputText.scrollHeight;

            if (result.success && reloadAfter.includes(command)) {
                Novarr.showToast('Done — refreshing…', 'success');
                Novarr.softRefresh(1200);
            }
        } catch (err) {
            setButtonState(btn, 'fail');
            outputText.textContent = `> ${command}\nError: ${err.message}`;
            Novarr.showToast(err.message, 'danger');
        }
    }

    // State is carried by a modifier class (styled in _views.scss) rather than
    // by rewriting className, so the button keeps its size and layout classes.
    function setButtonState(btn, state) {
        const show = cls => btn.querySelector(cls)?.classList.remove('d-none');
        const hide = cls => btn.querySelector(cls)?.classList.add('d-none');

        ['.cmd-label', '.cmd-spinner', '.cmd-done', '.cmd-fail'].forEach(hide);
        btn.classList.remove('cmd-state-done', 'cmd-state-fail');

        if (state === 'running') {
            show('.cmd-spinner');
            btn.disabled = true;
            return;
        }

        btn.disabled = false;
        show(state === 'done' ? '.cmd-done' : '.cmd-fail');
        btn.classList.add(state === 'done' ? 'cmd-state-done' : 'cmd-state-fail');

        setTimeout(() => {
            ['.cmd-done', '.cmd-fail'].forEach(hide);
            show('.cmd-label');
            btn.classList.remove('cmd-state-done', 'cmd-state-fail');
        }, 4000);
    }
})();
</script>
@endpush

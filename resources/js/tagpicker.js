/**
 * Multi-select tag picker: a dropdown of checkboxes over existing tags with
 * an inline "add new tag" affordance. Checkboxes are named tags[] so they
 * submit naturally inside a form; the novel page reads them via AJAX.
 *
 * Initialised over every [data-tag-picker] on the page.
 */
function initTagPicker(root) {
    const toggle = root.querySelector('.tag-picker-toggle');
    const menu = root.querySelector('.tag-picker-menu');
    const label = root.querySelector('.tag-picker-label');
    const search = root.querySelector('.tag-picker-search');
    const options = root.querySelector('.tag-picker-options');
    const newInput = root.querySelector('.tag-picker-new');
    const addBtn = root.querySelector('.tag-picker-add');
    const createUrl = root.dataset.createUrl;

    const updateLabel = () => {
        const names = [...options.querySelectorAll('input:checked')]
            .map(c => c.dataset.name);
        label.textContent = names.length ? names.join(', ') : 'Select tags…';
        label.classList.toggle('text-muted', names.length === 0);
    };

    toggle.addEventListener('click', () => menu.classList.toggle('d-none'));
    document.addEventListener('click', (e) => {
        if (!root.contains(e.target)) menu.classList.add('d-none');
    });

    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.toLowerCase();
            options.querySelectorAll('.tag-picker-option').forEach(opt => {
                opt.classList.toggle('d-none', !opt.textContent.toLowerCase().includes(q));
            });
        });
    }

    options.addEventListener('change', updateLabel);

    function addOption(id, name, checked = true) {
        // Reuse an existing option if the tag already appears.
        let existing = options.querySelector(`input[value="${id}"]`);
        if (existing) {
            existing.checked = checked;
        } else {
            const opt = document.createElement('label');
            opt.className = 'tag-picker-option';
            opt.innerHTML = `<input type="checkbox" name="tags[]" value="${id}" data-name=""><span></span>`;
            const input = opt.querySelector('input');
            input.dataset.name = name;
            input.checked = checked;
            opt.querySelector('span').textContent = name;
            options.appendChild(opt);
        }
        updateLabel();
    }

    async function createTag() {
        const name = (newInput.value || '').trim();
        if (!name) return;
        try {
            const response = await fetch(createUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ name }),
            });
            const data = await response.json();
            if (data.success) {
                addOption(data.id, data.name, true);
                newInput.value = '';
            } else if (window.Novarr) {
                Novarr.showToast(data.message || 'Could not add tag.', 'danger');
            }
        } catch (err) {
            if (window.Novarr) Novarr.showToast('Error: ' + err.message, 'danger');
        }
    }

    if (addBtn) addBtn.addEventListener('click', createTag);
    if (newInput) newInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); createTag(); }
    });

    updateLabel();
}

export function initTagPickers(scope = document) {
    scope.querySelectorAll('[data-tag-picker]').forEach(initTagPicker);
}

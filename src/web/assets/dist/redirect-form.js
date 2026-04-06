/** global: Craft */
/** global: Garnish */

if (typeof Newism === 'undefined') {
    Newism = {};
}
if (typeof Newism.notFoundRedirects === 'undefined') {
    Newism.notFoundRedirects = {};
}

Newism.notFoundRedirects.RedirectForm = Garnish.Base.extend({
    form: null,

    init: function (formSelector) {
        this.form = document.querySelector(formSelector);

        this._initStatusCodeToggle();
        this._initElementSelect();
        this._initToTypeToggle();
        this._initTestUrl();
        this._initNotes();
        this._initPatternReference();
        this._initElementChipEdit();
    },

    // ── 410 Status Code Toggle ──────────────────────────────────────
    _initStatusCodeToggle: function () {
        const statusSelect = this.form.querySelector('[data-field="statusCode"] select');
        const destinationFields = this.form.querySelector('[data-destination-fields]');

        if (statusSelect && destinationFields) {
            statusSelect.addEventListener('change', () => {
                destinationFields.classList.toggle('hidden', statusSelect.value === '404' || statusSelect.value === '410');
            });
        }
    },

    // ── Element Select → Readonly URL Preview ───────────────────────
    _initElementSelect: function () {
        const {form} = this;
        const toElementUrlInput = form.querySelector('[data-field="toElementUrl"] input');
        let bound = false;

        function bind() {
            if (bound) return;
            // Element select is a jQuery widget — need $() to access .data('elementSelect')
            const elementSelect = $(form).find('.elementselect').data('elementSelect');
            if (!elementSelect || !toElementUrlInput) return;

            bound = true;

            elementSelect.on('selectElements', (ev) => {
                const el = ev.elements[0];
                if (!el) {
                    toElementUrlInput.value = '';
                    return;
                }
                Craft.sendActionRequest('GET', 'not-found-redirects/redirects/element-url', {
                    params: {elementId: el.id},
                }).then((response) => {
                    toElementUrlInput.value = response.data && response.data.uri || '';
                }).catch(() => {
                    toElementUrlInput.value = '';
                });
            });
            elementSelect.on('removeElements', () => {
                toElementUrlInput.value = '';
            });
        }

        bind();
        const toTypeSelect = form.querySelector('[data-field="toType"] select');
        if (toTypeSelect) {
            toTypeSelect.addEventListener('change', () => {
                requestAnimationFrame(bind);
            });
        }
    },

    // ── Destination Type Toggle (Resolved Entry URI) ────────────────
    _initToTypeToggle: function () {
        const resolvedEntryUri = this.form.querySelector('[data-resolved-entry-uri]');
        const toTypeSelect = this.form.querySelector('[data-field="toType"] select');

        if (toTypeSelect && resolvedEntryUri) {
            toTypeSelect.addEventListener('change', () => {
                resolvedEntryUri.classList.toggle('hidden', toTypeSelect.value !== 'entry');
            });
        }
    },

    // ── Test URL ─────────────────────────────────────────────────────
    _initTestUrl: function () {
        const {form} = this;
        const testUrisInput = form.querySelector('[data-test-uris]');
        const resultsContainer = form.querySelector('[data-test-results]');

        if (!testUrisInput || !resultsContainer) return;

        let timer = null;

        const runTest = () => {
            const testUris = testUrisInput.value;
            if (!testUris.trim()) {
                resultsContainer.innerHTML = '';
                resultsContainer.classList.add('hidden');
                return;
            }

            // Read current form values — handle namespaced names
            const fromInput = form.querySelector('[name$="[from]"]') || form.querySelector('[name="from"]');
            const toInput = form.querySelector('[name$="[to]"]') || form.querySelector('[name="to"]');
            const toTypeSelect = form.querySelector('[name$="[toType]"]') || form.querySelector('[name="toType"]');
            const from = fromInput?.value || '';
            const to = toInput?.value || '';
            const toType = toTypeSelect?.value || 'url';

            if (!from) {
                resultsContainer.innerHTML = '';
                resultsContainer.classList.add('hidden');
                return;
            }

            // Read regex match toggle
            const regexMatchInput = form.querySelector('[name$="[regexMatch]"]') || form.querySelector('[name="regexMatch"]');
            const regexMatch = regexMatchInput ? (regexMatchInput.checked || regexMatchInput.value === '1') : false;

            const data = {from, to, toType, testUris, regexMatch: regexMatch ? 1 : 0};

            // If entry type, include the selected element ID
            if (toType === 'entry') {
                const elementSelect = $(form).find('.elementselect').data('elementSelect');
                const selectedElements = elementSelect?.$elements;
                if (selectedElements && selectedElements.length) {
                    data.toElementId = selectedElements.first().data('id');
                }
            }

            Craft.sendActionRequest('POST', 'not-found-redirects/redirects/test-match', {
                data,
            }).then((response) => {
                resultsContainer.innerHTML = response.data.html || '';
                resultsContainer.classList.remove('hidden');
            }).catch(() => {
                resultsContainer.innerHTML = '<p class="error">Test failed.</p>';
                resultsContainer.classList.remove('hidden');
            });
        };

        const debouncedTest = () => {
            clearTimeout(timer);
            timer = setTimeout(runTest, 300);
        };

        form.addEventListener('input', debouncedTest);
    },

    // ── Notes CRUD ──────────────────────────────────────────────────
    _initNotes: function () {
        const {form} = this;
        const notesList = form.querySelector('[data-notes-list]');
        const addNoteBtn = form.querySelector('[data-add-note-btn]');
        const self = this;

        if (addNoteBtn) {
            addNoteBtn.addEventListener('click', () => {
                const redirectIdInput = form.querySelector('[name$="[redirectId]"]') || form.querySelector('[name="redirectId"]');
                const redirectId = redirectIdInput ? redirectIdInput.value : '';
                const modal = new Craft.CpModal(
                    'not-found-redirects/notes/edit?redirectId=' + redirectId
                );
                modal.on('submit', () => {
                    self._refreshNotesList();
                });
            });
        }

        if (notesList) {
            notesList.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.note-edit');
                if (editBtn) {
                    e.preventDefault();
                    const modal = new Craft.CpModal(
                        'not-found-redirects/notes/edit?noteId=' + editBtn.dataset.noteId
                    );
                    modal.on('submit', () => {
                        self._refreshNotesList();
                    });
                    return;
                }

                const deleteBtn = e.target.closest('.note-delete');
                if (deleteBtn) {
                    e.preventDefault();
                    if (!confirm(Craft.t('not-found-redirects', 'Delete this note?'))) return;

                    Craft.sendActionRequest('POST', 'not-found-redirects/notes/delete', {
                        data: {id: deleteBtn.dataset.noteId},
                    }).then(() => {
                        self._refreshNotesList();
                        Craft.cp.displayNotice(Craft.t('not-found-redirects', 'Note deleted.'));
                    }).catch(() => {
                        Craft.cp.displayError(Craft.t('not-found-redirects', 'Could not delete note.'));
                    });
                }
            });
        }
    },

    _refreshNotesList: function () {
        const notesList = this.form.querySelector('[data-notes-list]');
        if (!notesList) return;

        const redirectId = notesList.dataset.redirectId;
        if (!redirectId) return;

        Craft.sendActionRequest('GET', 'not-found-redirects/notes/render-list', {
            params: {redirectId: redirectId},
        }).then((response) => {
            notesList.innerHTML = response.data.html || '';
        }).catch(() => {
            Craft.cp.displayError(Craft.t('not-found-redirects', 'Could not refresh notes.'));
        });
    },

    // ── Pattern Reference Slideout ──────────────────────────────────
    _initPatternReference: function () {
        this.form.addEventListener('click', (e) => {
            if (e.target.closest('[data-pattern-reference-btn]')) {
                new Craft.CpScreenSlideout('not-found-redirects/redirects/pattern-reference', {
                    containerElement: 'div',
                });
            }
        });
    },

    // ── Element Chip double-click → edit slideout ───────────────────
    _initElementChipEdit: function () {
        this.form.addEventListener('dblclick', (e) => {
            const chip = e.target.closest('.element.chip[data-id], .element.card[data-id]');
            if (chip) {
                new Craft.ElementEditorSlideout(chip);
            }
        });
    },
});

Newism.notFoundRedirects.initRedirectForm = function () {
    document.querySelectorAll('[data-redirect-form]').forEach((el) => {
        new Newism.notFoundRedirects.RedirectForm(el);
    });
};

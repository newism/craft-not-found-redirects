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
        this._initFromValueNormalisationAndSiteSelection();
        this._initFromPrefixDescription();
        this._initElementSelect();
        this._initToTypeToggle();
        this._initTestUrl();
        this._initNotes();
        this._initPatternReference();
        this._initElementChipEdit();
    },

    // ── Block Status Code Toggle ────────────────────────────────────
    _initStatusCodeToggle: function () {
        const statusSelect = this.form.querySelector('[data-field="statusCode"] select');
        const destinationFields = this.form.querySelector('[data-destination-fields]');

        if (statusSelect && destinationFields) {
            statusSelect.addEventListener('change', () => {
                destinationFields.classList.toggle('hidden', statusSelect.value === '404' || statusSelect.value === '410' || statusSelect.value === '444');
            });
        }
    },

    // ── Incoming URI value base path removal and site selection ─────
    _initFromValueNormalisationAndSiteSelection: function () {
        const {form} = this;
        const incomingUri = form.querySelector('[data-field="from"] input');
        const prefixContainer = form.querySelector('[data-from-prefix]');
        const siteSelect = form.querySelector('[data-field="fromPrefix"] select');

        if (!incomingUri || !prefixContainer || !siteSelect) return;

        // Create a map of the site prefixes keyed by site ID with the base path prefix and the data-site-absolute-url
        const sitePrefixes = Array.from(prefixContainer.children).reduce((acc, el) => {
            const siteId = el.dataset.siteId;
            if (siteId) {
                acc[siteId] = {
                    baseUrl: el.textContent.trim(),
                    absoluteUrl: el.dataset.siteAbsoluteUrl || '',
                };
            }
            return acc;
        }, {});

        // Plain objects always enumerate integer-like keys (site IDs) in
        // ascending numeric order, so sorting into an object would silently
        // discard this order. Keep it as an array of [siteId, prefix] pairs.
        const orderedSitePrefixes = Object.entries(sitePrefixes).sort(
            (a, b) => b[1].absoluteUrl.length - a[1].absoluteUrl.length
        );

        // Craft normalises the base path prefix to always start and end with a slash,
        // but the incoming URI value may not start with a slash so we need to account for that
        const findMatchingPrefix = (uri, site) => {
            const prefix = site.baseUrl;
            const prefixWithoutStartSlash = prefix.startsWith('/') ? prefix.slice(1) : prefix;
            let match = null;
            if (incomingUri.value.startsWith(site.absoluteUrl)) {
                match = site.absoluteUrl;
            } else if (incomingUri.value.startsWith(site.baseUrl)) {
                match = site.baseUrl;
            } else if (incomingUri.value.startsWith(prefixWithoutStartSlash)) {
                match = prefixWithoutStartSlash;
            }

            return match;
        }

        const normaliseValue = () => {
            if (!incomingUri.value) {
                return;
            }

            const selectedSiteId = siteSelect.value;
            // No site selected so check if the URL starts with a prefix, remove it and select the site
            if (selectedSiteId === '') {
                for (const [siteId, site] of orderedSitePrefixes) {
                    const match = findMatchingPrefix(incomingUri.value, site);
                    if (match) {
                        incomingUri.value = incomingUri.value.slice(match.length);
                        siteSelect.value = siteId;
                        // Trigger change event to update the prefix description
                        $(siteSelect).trigger('change');
                        break;
                    }
                }
            } else {
                // Site selected, ensure the prefix is removed from the incoming URI
                const site = sitePrefixes[selectedSiteId] || '';
                if (site) {
                    const match = findMatchingPrefix(incomingUri.value, site);
                    if (match) {
                        incomingUri.value = incomingUri.value.slice(match.length);
                    }
                }
            }
        };

        // Craft's native select "toggle" behaviour fires on the same change
        // event and swaps the .hidden classes — defer a frame so we read
        // them after it updates, not before.
        incomingUri.addEventListener('change', () => {
            requestAnimationFrame(normaliseValue);
        });

        normaliseValue();
    },

    // ── Incoming URI Site-Prefix Description (a11y) ──────────────────
    _initFromPrefixDescription: function () {
        const {form} = this;
        const siteSelect = form.querySelector('[data-field="fromPrefix"] select');
        const prefixContainer = form.querySelector('[data-from-prefix]');
        const descEl = form.querySelector('[data-from-prefix-desc]');

        if (!siteSelect || !prefixContainer || !descEl) return;

        const updateDescription = () => {
            const visiblePrefix = Array.from(prefixContainer.children)
                .find((el) => !el.classList.contains('hidden'));
            const prefixText = visiblePrefix ? visiblePrefix.textContent.trim() : '';

            descEl.textContent = prefixText && prefixText !== '*/'
                ? Craft.t('not-found-redirects', 'Path is relative to {prefix}.', {prefix: prefixText})
                : Craft.t('not-found-redirects', 'Path is relative to the selected site’s base URL.');
        };

        // Craft's native select "toggle" behaviour fires on the same change
        // event and swaps the .hidden classes — defer a frame so we read
        // them after it updates, not before.
        siteSelect.addEventListener('change', () => {
            requestAnimationFrame(updateDescription);
        });

        updateDescription();
    },

    // ── Element Select → Readonly URL Preview ───────────────────────
    _initElementSelect: function () {
        const {form} = this;
        const toElementUrlInput = form.querySelector('[data-field="toElementUrl"] input');
        const toElementSiteIdInput = form.querySelector('[data-field="toElementSiteId"]');
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
                    if (toElementSiteIdInput) toElementSiteIdInput.value = '';
                    return;
                }
                // Capture the site the entry was selected on so the destination
                // resolves to that site's (possibly cross-site) domain.
                if (toElementSiteIdInput) toElementSiteIdInput.value = el.siteId || '';
                Craft.sendActionRequest('GET', 'not-found-redirects/redirects/element-url', {
                    params: {elementId: el.id, siteId: el.siteId || ''},
                }).then((response) => {
                    toElementUrlInput.value = response.data && response.data.uri || '';
                }).catch(() => {
                    toElementUrlInput.value = '';
                });
            });
            elementSelect.on('removeElements', () => {
                toElementUrlInput.value = '';
                if (toElementSiteIdInput) toElementSiteIdInput.value = '';
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

            // Include the redirect's site so test URIs get the same site base
            // path stripping the runtime applies to requests (e.g. /en/foo → foo)
            const siteSelect = form.querySelector('[name$="[siteId]"]') || form.querySelector('[name="siteId"]');
            if (siteSelect && siteSelect.value) {
                data.siteId = siteSelect.value;
            }

            // If entry type, include the selected element ID and its site
            if (toType === 'entry') {
                const elementSelect = $(form).find('.elementselect').data('elementSelect');
                const selectedElements = elementSelect?.$elements;
                if (selectedElements && selectedElements.length) {
                    data.toElementId = selectedElements.first().data('id');
                }
                const siteIdInput = form.querySelector('[data-field="toElementSiteId"]');
                if (siteIdInput && siteIdInput.value) {
                    data.toElementSiteId = siteIdInput.value;
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

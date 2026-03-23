$(function() {
    // ── 410 Status Code Toggle ────────────────────────────────────────
    const $statusCode = $('[name="statusCode"]');
    const $destinationFields = $('#destination-fields');

    $statusCode.on('change', function() {
        $destinationFields.toggleClass('hidden', $statusCode.val() === '410');
    });

    // ── Element Select → Readonly URL Preview ─────────────────────────
    const $toElementUrl = $('#toElementUrl');
    let elementSelectBound = false;

    function bindElementSelect() {
        if (elementSelectBound) return;
        const elementSelect = $('#toElementId').data('elementSelect');
        if (!elementSelect || !$toElementUrl.length) return;

        elementSelectBound = true;

        elementSelect.on('selectElements', function(ev) {
            const el = ev.elements[0];
            if (!el) {
                $toElementUrl.val('');
                return;
            }
            Craft.sendActionRequest('GET', 'not-found-redirects/redirects/element-url', {
                params: { elementId: el.id },
            }).then(function(response) {
                $toElementUrl.val(response.data?.uri || '');
            }).catch(function() {
                $toElementUrl.val('');
            });
        });
        elementSelect.on('removeElements', function() {
            $toElementUrl.val('');
        });
    }

    // Try now, and also retry when destination type changes to 'entry'
    bindElementSelect();
    $('[name="toType"]').on('change', function() {
        setTimeout(bindElementSelect, 100);
    });

    // ── Notes CRUD ────────────────────────────────────────────────────
    const $notesList = $('#notes-list');
    const $addNoteBtn = $('#add-note-btn');

    if ($addNoteBtn.length) {
        $addNoteBtn.on('click', function() {
            const redirectId = $('[name="redirectId"]').val();
            const modal = new Craft.CpModal(
                'not-found-redirects/notes/edit?redirectId=' + redirectId
            );
            modal.on('submit', function() {
                window.location.reload();
            });
        });
    }

    $notesList.on('click', '.note-edit', function(e) {
        e.preventDefault();
        const noteId = $(this).data('note-id');
        const modal = new Craft.CpModal(
            'not-found-redirects/notes/edit?noteId=' + noteId
        );
        modal.on('submit', function() {
            window.location.reload();
        });
    });

    $notesList.on('click', '.note-delete', function(e) {
        e.preventDefault();
        const $row = $(this).closest('.note-card');
        const noteId = $(this).data('note-id');

        if (!confirm('Delete this note?')) {
            return;
        }

        Craft.sendActionRequest('POST', 'not-found-redirects/notes/delete', {
            data: { id: noteId },
        }).then(() => {
            $row.remove();
            Craft.cp.displayNotice('Note deleted.');
        }).catch(() => {
            Craft.cp.displayError('Could not delete note.');
        });
    });
});

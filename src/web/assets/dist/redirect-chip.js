/** global: Craft */
/** global: Garnish */

if (typeof Newism === 'undefined') {
    Newism = {};
}
if (typeof Newism.notFoundRedirects === 'undefined') {
    Newism.notFoundRedirects = {};
}

Newism.notFoundRedirects.RedirectChip = Garnish.Base.extend({
    container: null,
    entryId: null,

    init: function (chipSelector) {
        const $chip = $(chipSelector);
        const disclosureMenu = $chip
            .find('> .chip-content > .chip-actions .action-btn')
            .disclosureMenu()
            .data('disclosureMenu');

        if (!disclosureMenu) return;

        $chip.on('dblclick taphold', (e) => {
            if (e.type === 'taphold' && e.target.nodeName === 'BUTTON') return;
            if ($(e.target).closest('a[href],button,[role=button]').length) return;
            disclosureMenu.$container.find('[data-edit-action]').trigger('click');
        });
    },
});

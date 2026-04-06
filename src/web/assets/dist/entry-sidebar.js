/** global: Craft */
/** global: Garnish */

if (typeof Newism === 'undefined') {
    Newism = {};
}
if (typeof Newism.notFoundRedirects === 'undefined') {
    Newism.notFoundRedirects = {};
}

Newism.notFoundRedirects.EntrySidebar = Garnish.Base.extend({
    container: null,
    entryId: null,

    init: function (containerSelector) {


        this.container = document.querySelector(containerSelector);

        if (this.container.dataset.initialised ?? false) {
            return;
        }
        this.container.dataset.initialised = true;

        this.entryId = this.container.dataset.entryId;
        // Add Redirect button
        this.container.addEventListener('click', (e) => {
            if (e.target.closest('[data-add-redirect-btn]')) {
                this.openNewSlideout();
            }
        });

        document.addEventListener('notFoundRedirects:redirectSaved', this.refreshSidebar.bind(this));
        document.addEventListener('notFoundRedirects:redirectDeleted', this.refreshSidebar.bind(this));
    },

    openNewSlideout: function () {
        const params = new URLSearchParams({
            toType: 'entry',
            toElementId: this.entryId,
        });

        const slideout = new Craft.CpScreenSlideout(
            'not-found-redirects/redirects/edit?' + params.toString()
        );

        slideout.on('submit', (e) => {
            document.dispatchEvent(new CustomEvent('notFoundRedirects:redirectSaved', {
                detail: {
                    redirectId: e.response?.data?.modelId
                }
            }));
        });
    },

    refreshSidebar: function () {
        Craft.sendActionRequest('GET', 'not-found-redirects/redirects/render-entry-sidebar', {
            params: {entryId: this.entryId},
        }).then((response) => {
            const tmp = document.createElement('div');
            tmp.innerHTML = response.data.html;
            const newContent = tmp.firstElementChild;
            this.container.innerHTML = newContent ? newContent.innerHTML : '';
            // Run registered JS (activate handlers from getActionMenuItems)
            Craft.appendHeadHtml(response.data.headHtml ?? '');
            Craft.appendBodyHtml(response.data.bodyHtml ?? '');
            // Re-init disclosure menus on new chips
            Craft.initUiElements($(this.container));
        }).catch((e) => {
            const message = e?.response?.data?.message || Craft.t('not-found-redirects', 'Could not refresh redirects.');
            Craft.cp.displayError(message);
        });
    },
});

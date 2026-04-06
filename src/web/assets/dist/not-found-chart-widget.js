/** global: Craft */
/** global: Garnish */

if (typeof Newism === 'undefined') {
    Newism = {};
}
if (typeof Newism.notFoundRedirects === 'undefined') {
    Newism.notFoundRedirects = {};
}

Newism.notFoundRedirects.NotFoundChartWidget = Garnish.Base.extend({
    init: function (widgetId, settings) {
        this.setSettings(settings);

        const $widget = $('#widget' + widgetId);
        const $body = $widget.find('.body:first');
        const $chartContainer = $body.find('.chart');
        const $error = $('<div class="error hidden"/>').appendTo($body);

        Craft.sendActionRequest('POST', 'not-found-redirects/not-found-uris/chart-data', {
            data: {
                dateRange: this.settings.dateRange,
                display: this.settings.display || 'perDay',
            },
        }).then((response) => {
            if (!response.data.total) {
                $chartContainer.replaceWith('<p class="zilch small">' + Craft.t('not-found-redirects', 'No 404s recorded in this period.') + '</p>');
                return;
            }

            const chart = new Craft.charts.Area($chartContainer, {
                yAxis: {
                    formatter: (chartObj) => (d) => {
                        const format = d !== Math.round(d) ? ',.1f' : ',.0f';
                        return chartObj.formatLocale.format(format)(d);
                    },
                },
            });

            const chartDataTable = new Craft.charts.DataTable(response.data.dataTable);

            chart.draw(chartDataTable, {
                orientation: response.data.orientation,
                dataScale: response.data.scale,
                formats: response.data.formats,
            });

            // Resize chart when dashboard grid refreshes
            window.dashboard?.grid?.on('refreshCols', () => chart.resize());
        }).catch(({response}) => {
            const msg = response?.data?.message || Craft.t('app', 'A server error occurred.');
            $error.html(msg).removeClass('hidden');
        });
    },
});

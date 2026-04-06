<?php

namespace newism\notfoundredirects\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Json;
use newism\notfoundredirects\web\assets\NotFoundChartWidgetAsset;

class NotFoundChartWidget extends Widget
{
    public string $dateRange = 'd30';
    public string $display = 'perDay';

    public static function displayName(): string
    {
        return Craft::t('not-found-redirects', '404s Created');
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public function getSubtitle(): ?string
    {
        $range = match ($this->dateRange) {
            'd7' => Craft::t('not-found-redirects', 'Last 7 days'),
            'd30' => Craft::t('not-found-redirects', 'Last 30 days'),
            'lastweek' => Craft::t('not-found-redirects', 'Previous week'),
            'lastmonth' => Craft::t('not-found-redirects', 'Previous month'),
            default => null,
        };

        $displayLabel = match ($this->display) {
            'cumulative' => Craft::t('not-found-redirects', 'cumulative'),
            default => Craft::t('not-found-redirects', 'per day'),
        };

        return $range ? $range . ' — ' . $displayLabel : null;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['dateRange'], 'in', 'range' => ['d7', 'd30', 'lastweek', 'lastmonth']];
        $rules[] = [['display'], 'in', 'range' => ['perDay', 'cumulative']];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'not-found-redirects/_widgets/not-found-chart/settings',
            ['widget' => $this],
        );
    }

    public function getBodyHtml(): ?string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('not-found-redirects:view404s')) {
            return null;
        }

        $options = [
            'dateRange' => $this->dateRange,
            'display' => $this->display,
            'orientation' => Craft::$app->getLocale()->getOrientation(),
        ];

        $view = Craft::$app->getView();
        $view->registerAssetBundle(NotFoundChartWidgetAsset::class);
        $view->registerJs(
            'new Newism.notFoundRedirects.NotFoundChartWidget(' . $this->id . ', ' . Json::encode($options) . ');'
        );

        return '<div class="chart" style="height: 200px; margin: 0;"></div>';
    }
}

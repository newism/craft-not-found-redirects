<?php

namespace newism\notfoundredirects\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Json;
use newism\notfoundredirects\web\assets\NotFoundCoverageWidgetAsset;

class NotFoundCoverageWidget extends Widget
{
    public string $dateRange = 'd30';

    public static function displayName(): string
    {
        return Craft::t('not-found-redirects', 'Unhandled 404s');
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

        return $range ? $range : null;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['dateRange'], 'in', 'range' => ['d7', 'd30', 'lastweek', 'lastmonth']];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'not-found-redirects/_widgets/not-found-coverage/settings',
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
            'orientation' => Craft::$app->getLocale()->getOrientation(),
        ];

        $view = Craft::$app->getView();
        $view->registerAssetBundle(NotFoundCoverageWidgetAsset::class);
        $view->registerJs(
            'new Newism.notFoundRedirects.NotFoundCoverageWidget(' . $this->id . ', ' . Json::encode($options) . ');'
        );

        return '<div class="chart" style="height: 200px; margin: 0;"></div>';
    }
}

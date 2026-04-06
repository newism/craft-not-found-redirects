<?php

namespace newism\notfoundredirects\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class NotFoundChartWidgetAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['not-found-chart-widget.js'];

        parent::init();
    }
}

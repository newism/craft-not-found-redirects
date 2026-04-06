<?php

namespace newism\notfoundredirects\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class NotFoundCoverageWidgetAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['not-found-coverage-widget.js'];

        parent::init();
    }
}

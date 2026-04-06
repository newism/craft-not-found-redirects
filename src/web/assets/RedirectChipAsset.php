<?php

namespace newism\notfoundredirects\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class RedirectChipAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['redirect-chip.js'];

        parent::init();
    }
}

<?php

namespace newism\notfoundredirects\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class EntrySidebarAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['entry-sidebar.js'];

        parent::init();
    }
}

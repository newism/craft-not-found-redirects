<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// The global Yii/Craft classes aren't part of the PSR-4 autoload maps
// (they're un-namespaced facades), so they have to be pulled in explicitly.
// Tests don't bootstrap a full Craft application — they only need the
// class definitions so Craft::$app can be swapped for a lightweight fake.
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';

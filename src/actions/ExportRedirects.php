<?php

namespace newism\notfoundredirects\actions;

use newism\notfoundredirects\models\ExportResult;
use newism\notfoundredirects\query\RedirectQuery;

class ExportRedirects extends ExportAction
{
    public function handle(string $format = 'json'): ExportResult
    {
        $items = RedirectQuery::find()->collect();

        return $this->buildResult($items, $format, 'not-found-redirects-redirects', ['notes']);
    }
}

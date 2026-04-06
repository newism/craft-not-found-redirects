<?php

namespace newism\notfoundredirects\actions;

use newism\notfoundredirects\models\ExportResult;
use newism\notfoundredirects\query\NotFoundUriQuery;

class ExportNotFoundUris extends ExportAction
{
    public function handle(string $format = 'json'): ExportResult
    {
        $items = NotFoundUriQuery::find()->collect();
        $expand = $format === 'json' ? ['referrers'] : [];

        return $this->buildResult($items, $format, 'not-found-redirects-404s', $expand);
    }
}

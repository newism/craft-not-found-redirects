<?php

namespace newism\notfoundredirects\models;

class ExportResult
{
    public function __construct(
        public int    $count = 0,
        public string $content = '',
        public string $format = 'csv',
        public string $filename = '',
    )
    {
    }
}

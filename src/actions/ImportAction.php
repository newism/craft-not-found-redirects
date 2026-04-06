<?php

namespace newism\notfoundredirects\actions;

use craft\base\Model;
use League\Csv\Reader;

abstract class ImportAction
{
    public const SOURCE_PLUGIN = 'plugin';
    public const SOURCE_RETOUR = 'retour';

    /**
     * Build a map of CSV label → attribute name for a model class.
     * Built once per import, used to remap CSV column headers to attribute names.
     */
    protected function buildLabelToAttributeMap(string $modelClass): array
    {
        /** @var Model $model */
        $model = new $modelClass();
        $map = [];
        foreach ([...$model->attributes(), ...$model->extraFields()] as $attr) {
            $map[$model->getAttributeLabel($attr)] = $attr;
        }
        return $map;
    }

    protected function detectFormat(string $input): string
    {
        $trimmed = ltrim($input);
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            if (json_decode($trimmed) !== null) {
                return 'json';
            }
        }
        return 'csv';
    }

    protected function detectSource(array $records): string
    {
        if (!$records) {
            return self::SOURCE_PLUGIN;
        }

        $columns = array_keys($records[0]);

        if (in_array('404 File Not Found URL', $columns, true)) {
            return self::SOURCE_RETOUR;
        }

        if (in_array('Legacy URL Pattern', $columns, true)) {
            return self::SOURCE_RETOUR;
        }

        return self::SOURCE_PLUGIN;
    }

    protected function parseCsv(string $input): array
    {
        return array_values(iterator_to_array(
            Reader::createFromString($input)->setHeaderOffset(0)->getRecords()
        ));
    }

    protected function parseJson(string $input): array
    {
        return json_decode($input, true);
    }
}

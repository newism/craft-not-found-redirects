<?php

namespace newism\notfoundredirects\actions;

use craft\base\Model;
use DateTime;
use Illuminate\Support\Collection;
use League\Csv\Writer;
use newism\notfoundredirects\models\ExportResult;

abstract class ExportAction
{
    /**
     * @param Collection<Model> $items
     * @param string[] $expand Extra fields to include (maps to Model::extraFields)
     */
    protected function buildResult(Collection $items, string $format, string $filenameBase, array $expand = []): ExportResult
    {
        $result = new ExportResult();
        $result->format = $format;
        $result->count = $items->count();
        $result->filename = $filenameBase . '-' . date('Y-m-d-His') . '.' . $format;

        if ($format === 'json') {
            $result->content = json_encode(
                $items->map(fn(Model $model) => $model->toArray([], $expand))->all(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } else {
            /** @var Model|null $first */
            $first = $items->first();
            $attributes = $first ? [...$first->attributes(), ...$expand] : [];

            $rows = $items->map(fn(Model $model) => array_map(
                fn(string $attr) => $model->$attr,
                $attributes
            ))->all();

            $csv = Writer::createFromString();
            $csv->addFormatter(function (array $record): array {
                return array_map(function ($value) {
                    if ($value instanceof DateTime) return $value->format(DATE_W3C);
                    if (is_bool($value)) return $value ? 'TRUE' : 'FALSE';
                    if (is_array($value) || $value instanceof Collection) return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return $value;
                }, $record);
            });
            $headers = array_map(fn(string $attr) => $first->getAttributeLabel($attr), $attributes);
            $csv->insertOne($headers);
            $csv->insertAll($rows);
            $result->content = $csv->toString();
        }

        return $result;
    }
}

<?php

namespace newism\notfoundredirects\actions;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\ImportResult;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Referrer;
use newism\notfoundredirects\NotFoundRedirects;

class ImportNotFoundUris extends ImportAction
{
    /**
     * @param string|NotFoundUri[] $input Raw string (CSV/JSON) or pre-built models
     */
    public function handle(string|array $input, ?string $format = null, ?string $source = null): ImportResult
    {
        $models = is_array($input)
            ? $input
            : $this->parse($input, $format, $source);

        return $this->import($models);
    }

    private function parse(string $input, ?string $format, ?string $source): array
    {
        $format ??= $this->detectFormat($input);
        $records = match ($format) {
            'json' => $this->parseJson($input),
            default => $this->parseCsv($input),
        };

        if ($format === 'json') {
            return array_map(NotFoundUri::fromJsonObject(...), $records);
        }

        $source ??= $this->detectSource($records);
        if ($source === self::SOURCE_RETOUR) {
            return array_map(NotFoundUri::fromRetourCsvRow(...), $records);
        }

        // Native CSV — remap labels to attribute names, then hydrate
        $labelMap = $this->buildLabelToAttributeMap(NotFoundUri::class);
        return array_map(fn(array $record) => NotFoundUri::fromCsvRow($record, $labelMap), $records);
    }

    private function import(array $models): ImportResult
    {
        $existing = (new Query())
            ->select(['id', 'uri', 'siteId', 'uid'])
            ->from(Table::NOT_FOUND_URIS)
            ->indexBy(fn($row) => $row['uri'] . ':' . $row['siteId'])
            ->all();

        $notFoundUriService = NotFoundRedirects::getInstance()->getNotFoundUriService();
        $result = new ImportResult();

        foreach ($models as $i => $model) {
            $key = $model->uri . ':' . $model->siteId;
            $match = $existing[$key] ?? null;
            if ($match) {
                $model->id = $match['id'];
                $model->uid = $match['uid'];
            }

            if (!$notFoundUriService->saveNotFoundUri($model)) {
                $result->errors[$i] = $model;
                continue;
            }
            $result->imported[$i] = $model;

            // Persist nested referrers from JSON import
            foreach ($model->getReferrers() as $ref) {
                if (!$ref->id && $ref->referrer) {
                    Craft::$app->getDb()->createCommand()->upsert(
                        Table::REFERRERS,
                        [
                            'notFoundId' => $model->id,
                            'referrer' => $ref->referrer,
                            'hitCount' => $ref->hitCount,
                            'hitLastTime' => Db::prepareDateForDb($ref->hitLastTime ?? new DateTime()),
                        ],
                        [
                            'hitCount' => $ref->hitCount,
                            'hitLastTime' => Db::prepareDateForDb($ref->hitLastTime ?? new DateTime()),
                        ],
                    )->execute();
                }
            }
        }

        return $result;
    }
}

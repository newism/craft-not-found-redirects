<?php

namespace newism\notfoundredirects\actions;

use craft\db\Query;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\ImportResult;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\Exception;

class ImportRedirects extends ImportAction
{
    /**
     * Handle different inputs before import.
     *
     * @param string|Redirect[] $input Raw string (CSV/JSON) or pre-built models
     */
    public function handle(string|array $input, ?string $format = null, ?string $source = null): ImportResult
    {
        $models = is_array($input)
            ? $input
            : $this->parse($input, $format, $source);

        return $this->import($models);
    }

    /**
     * @param string $input
     * @param string|null $format
     * @param string|null $source
     * @return Redirect[]
     * @throws Exception
     */
    private function parse(string $input, ?string $format, ?string $source): array
    {
        $format ??= $this->detectFormat($input);
        $records = match ($format) {
            'json' => $this->parseJson($input),
            default => $this->parseCsv($input),
        };

        $source ??= $this->detectSource($records);

        if ($source === self::SOURCE_RETOUR) {
            return array_map(Redirect::fromRetourCsvRow(...), $records);
        }

        if ($source === self::SOURCE_PLUGIN) {
            if ($format === 'json') {
                return array_map(Redirect::fromJsonObject(...), $records);
            }

            if ($format === 'csv') {
                // Native CSV — remap labels to attribute names, then hydrate
                $labelMap = $this->buildLabelToAttributeMap(Redirect::class);
                return array_map(fn(array $record) => Redirect::fromCsvRow($record, $labelMap), $records);
            }
            throw new Exception("Unable to parse input: unrecognized format '$format'");
        }

        throw new Exception("Unable to parse input: unrecognized source '$source'");
    }

    private function import(array $models): ImportResult
    {
        $existing = (new Query())
            ->select(['id', 'from', 'siteId', 'uid'])
            ->from(Table::REDIRECTS)
            ->indexBy(fn($row) => $row['from'] . ':' . ($row['siteId'] ?? 'all'))
            ->all();

        $redirectService = NotFoundRedirects::getInstance()->getRedirectService();
        $noteService = NotFoundRedirects::getInstance()->getNoteService();
        $result = new ImportResult();

        foreach ($models as $i => $model) {
            $key = $model->from . ':' . ($model->siteId ?? 'all');
            $match = $existing[$key] ?? null;
            if ($match) {
                $model->id = $match['id'];
                $model->uid = $match['uid'];
            }

            if (!$redirectService->saveRedirect($model)) {
                $result->errors[$i] = $model;
                continue;
            }
            $result->imported[$i] = $model;

            // Persist nested notes from JSON import
            foreach ($model->getNotes() as $note) {
                if (!$note->id && $note->note) {
                    $noteService->addNote($model->id, $note->note, systemGenerated: $note->systemGenerated);
                }
            }
        }

        return $result;
    }
}

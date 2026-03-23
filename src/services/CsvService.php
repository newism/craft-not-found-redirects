<?php

namespace newism\notfoundredirects\services;

use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use League\Csv\Reader;
use League\Csv\Writer;
use newism\notfoundredirects\models\NotFoundUri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use yii\db\Expression;

class CsvService extends Component
{
    /**
     * Export 404 records as CSV.
     *
     * @return int Number of records exported
     */
    public function export404s(string $path = 'php://output'): int
    {
        $items = NotFoundRedirects::getInstance()->notFound->find(limit: 10000);

        $headers = ['URI', 'Full URL', 'Hits', 'Last Hit', 'Handled', 'First Seen'];
        $rows = $items->map(fn(NotFoundUri $nf) => [
            $nf->uri,
            $nf->fullUrl,
            $nf->hitCount,
            $nf->hitLastTime?->format('Y-m-d H:i:s'),
            $nf->handled ? '1' : '0',
            $nf->dateCreated?->format('Y-m-d H:i:s'),
        ])->all();

        $this->writeCsv($path, $headers, $rows);

        return count($rows);
    }

    /**
     * Export redirect records as CSV.
     *
     * @return int Number of records exported
     */
    public function exportRedirects(string $path = 'php://output'): int
    {
        $items = NotFoundRedirects::getInstance()->redirects->find(limit: 10000);

        // Batch-load the latest note per redirect in a single query
        $redirectIds = $items->pluck('id')->filter()->all();
        $latestNotes = [];
        if (!empty($redirectIds)) {
            $maxIdSubquery = (new Query())
                ->select([new Expression('MAX([[id]])')])
                ->from('{{%notfoundredirects_notes}}')
                ->where(['redirectId' => $redirectIds])
                ->groupBy('redirectId');

            $latestNotes = (new Query())
                ->from('{{%notfoundredirects_notes}}')
                ->where(['id' => $maxIdSubquery])
                ->indexBy('redirectId')
                ->all();
        }

        $headers = ['From', 'To', 'To Type', 'To Element ID', 'Status Code', 'Priority', 'Enabled', 'System Generated', 'Start Date', 'End Date', 'Hits', 'Last Hit', 'Note'];
        $rows = $items->map(function(Redirect $r) use ($latestNotes) {
            $latestNote = $latestNotes[$r->id] ?? null;
            return [
                $r->from,
                $r->to,
                $r->toType,
                $r->toElementId,
                $r->statusCode,
                $r->priority,
                $r->enabled ? '1' : '0',
                $r->systemGenerated ? '1' : '0',
                $r->startDate?->format('Y-m-d H:i:s'),
                $r->endDate?->format('Y-m-d H:i:s'),
                $r->hitCount,
                $r->hitLastTime?->format('Y-m-d H:i:s'),
                $latestNote['note'] ?? '',
            ];
        })->all();

        $this->writeCsv($path, $headers, $rows);

        return count($rows);
    }

    /**
     * Import redirect records from a CSV file.
     *
     * @return array{dryRun: bool, summary: array, items: array}
     */
    public function importRedirects(string $path, bool $dryRun = false): array
    {
        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);

        $plugin = NotFoundRedirects::getInstance();
        $redirectService = $plugin->redirects;
        $noteService = $plugin->notes;
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $items = [];

        foreach ($csv->getRecords() as $record) {
            $from = $record['From'] ?? $record['from'] ?? null;
            if (!$from) {
                $skipped++;
                continue;
            }

            $model = new Redirect();
            $model->from = $from;
            $model->to = $record['To'] ?? $record['to'] ?? '';
            $model->toType = $record['To Type'] ?? $record['toType'] ?? 'url';
            $model->statusCode = (int) ($record['Status Code'] ?? $record['statusCode'] ?? 302);
            $model->priority = (int) ($record['Priority'] ?? $record['priority'] ?? 0);
            $model->enabled = filter_var($record['Enabled'] ?? $record['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $model->systemGenerated = filter_var($record['System Generated'] ?? $record['systemGenerated'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $toElementId = $record['To Element ID'] ?? $record['toElementId'] ?? null;
            $model->toElementId = $toElementId ? (int) $toElementId : null;

            $startDate = $record['Start Date'] ?? $record['startDate'] ?? null;
            $model->startDate = $startDate ? DateTimeHelper::toDateTime($startDate) : null;

            $endDate = $record['End Date'] ?? $record['endDate'] ?? null;
            $model->endDate = $endDate ? DateTimeHelper::toDateTime($endDate) : null;

            if ($dryRun) {
                // Validate without saving
                if ($model->validate()) {
                    $imported++;
                    $items[] = ['from' => $from, 'to' => $model->to, 'statusCode' => $model->statusCode, 'result' => 'would import'];
                } else {
                    $errors++;
                    $items[] = ['from' => $from, 'to' => $model->to, 'statusCode' => $model->statusCode, 'result' => 'error', 'error' => implode(', ', $model->getErrorSummary(true))];
                }
            } elseif ($redirectService->save($model)) {
                $imported++;
                $items[] = ['from' => $from, 'to' => $model->to, 'statusCode' => $model->statusCode, 'result' => 'imported'];

                $noteService->addNote($model->id, 'Imported from CSV', systemGenerated: true);

                $note = trim($record['Note'] ?? $record['note'] ?? '');
                if ($note) {
                    $noteService->addNote($model->id, $note);
                }
            } else {
                $errors++;
                $items[] = ['from' => $from, 'to' => $model->to, 'statusCode' => $model->statusCode, 'result' => 'error', 'error' => implode(', ', $model->getErrorSummary(true))];
            }
        }

        return [
            'dryRun' => $dryRun,
            'summary' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
            'items' => $items,
        ];
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        if ($path === 'php://output') {
            $csv = Writer::createFromFileObject(new \SplTempFileObject());
            $csv->insertOne($headers);
            $csv->insertAll($rows);
            $csv->output('export.csv');
        } else {
            $csv = Writer::createFromPath($path, 'w');
            $csv->insertOne($headers);
            $csv->insertAll($rows);
        }
    }
}

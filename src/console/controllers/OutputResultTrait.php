<?php

namespace newism\notfoundredirects\console\controllers;

use Craft;
use craft\helpers\Console;
use craft\helpers\Json;
use newism\notfoundredirects\NotFoundRedirects;
use yii\console\ExitCode;

trait OutputResultTrait
{
    /**
     * Output a structured result as table or JSON.
     *
     * @param array $result ['dryRun' => bool, 'summary' => [...], 'items' => [...]]
     * @param array $tableHeaders Column headers for table mode
     * @param callable $rowMapper Maps each item to a table row array
     * @param array $labels Maps summary keys to display labels
     */
    private function outputResult(array $result, array $tableHeaders, callable $rowMapper, array $labels): int
    {
        if ($this->outputFormat === 'json') {
            $this->stdout(Json::encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return ExitCode::OK;
        }

        // Table format
        if (!empty($result['items'])) {
            $this->stdout(PHP_EOL);
            $this->table($tableHeaders, array_map($rowMapper, $result['items']));
        }

        // Summary list
        $this->stdout(PHP_EOL);
        $summaryParts = [];
        foreach ($result['summary'] as $key => $count) {
            $label = $labels[$key] ?? $key;
            $this->stdout("  {$label}: {$count}\n", Console::FG_GREEN);
            $summaryParts[] = "{$label}: {$count}";
        }
        $this->stdout(PHP_EOL);

        // Log summary
        Craft::info(
            ($result['dryRun'] ? '[DRY RUN] ' : '') . implode(', ', $summaryParts),
            NotFoundRedirects::LOG,
        );

        return ExitCode::OK;
    }
}

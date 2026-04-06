<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;

class ImportResult
{
    public function __construct(
        /** @var array<int, Model> Successfully imported models, keyed by input index */
        public array $imported = [],
        /** @var array<int, Model> Skipped models, keyed by input index */
        public array $skipped = [],
        /** @var array<int, Model> Models that failed validation, keyed by input index */
        public array $errors = [],
    )
    {
    }

    /**
     * All models (imported + skipped + errors), keyed by input index.
     * @return array<int, Model>
     */
    public function getResults(): array
    {
        return $this->imported + $this->skipped + $this->errors;
    }
}

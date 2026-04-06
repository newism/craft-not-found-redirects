<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;
use DateTime;

class Referrer extends Model
{
    public ?int $id = null;
    public ?int $notFoundId = null;
    public ?string $referrer = null;
    public int $hitCount = 1;
    public ?DateTime $hitLastTime = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['notFoundId', 'referrer'], 'required'],
            [['notFoundId', 'hitCount'], 'integer'],
            [['referrer'], 'string', 'max' => 500],
        ];
    }

    /**
     * Creates a new instance from a raw database row.
     * Uses setAttributes() with safe-attribute validation disabled, writing values directly
     * to attributes without invoking setters — appropriate since DB data is already in its
     * stored, normalized form and requires no further transformation.
     */
    public static function fromDbRow(array $row): self
    {
        $model = new self();
        $model->setAttributes($row, false);
        return $model;
    }
}

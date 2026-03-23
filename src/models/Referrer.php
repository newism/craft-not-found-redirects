<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
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
            [['referrer'], 'string', 'max' => 2000],
        ];
    }

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int) $row['id'];
        $model->notFoundId = (int) $row['notFoundId'];
        $model->referrer = $row['referrer'];
        $model->hitCount = (int) $row['hitCount'];
        $model->hitLastTime = DateTimeHelper::toDateTime($row['hitLastTime']) ?: null;
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;
        $model->uid = $row['uid'];

        return $model;
    }
}

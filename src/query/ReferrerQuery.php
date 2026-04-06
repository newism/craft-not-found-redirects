<?php

namespace newism\notfoundredirects\query;

use craft\db\Query;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\Referrer;

class ReferrerQuery extends Query
{
    public static function find(): static
    {
        return new static();
    }

    public ?int $id = null;
    public ?int $notFoundId = null;

    public function one($db = null): mixed
    {
        $row = parent::one($db);
        return $row ? Referrer::fromDbRow($row) : null;
    }

    public function populate($rows): array
    {
        return array_map(Referrer::fromDbRow(...), $rows);
    }

    public function prepare($builder)
    {
        $this->from(Table::REFERRERS);

        if ($this->id !== null) {
            $this->andWhere(['id' => $this->id]);
        }

        if ($this->notFoundId !== null) {
            $this->andWhere(['notFoundId' => $this->notFoundId]);
        }

        return parent::prepare($builder);
    }

}
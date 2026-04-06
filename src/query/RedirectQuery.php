<?php

namespace newism\notfoundredirects\query;

use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\Redirect;

class RedirectQuery extends Query
{
    public static function find(): static
    {
        return new static();
    }

    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $search = null;
    public ?bool $systemGenerated = null;
    public ?bool $enabled = null;
    public bool $activeNow = false;

    public function one($db = null): mixed
    {
        $row = parent::one($db);
        return $row ? Redirect::fromDbRow($row) : null;
    }

    public function populate($rows): array
    {
        return array_map(Redirect::fromDbRow(...), $rows);
    }

    public function prepare($builder)
    {
        $this->from(Table::REDIRECTS);

        if ($this->id !== null) {
            $this->andWhere(['id' => $this->id]);
        }

        if ($this->siteId !== null) {
            $this->andWhere(['or', ['siteId' => $this->siteId], ['siteId' => null]]);
        }

        if ($this->search !== null) {
            $this->andWhere([
                'or',
                ['like', 'from', $this->search],
                ['like', 'to', $this->search],
            ]);
        }

        if ($this->systemGenerated !== null) {
            $this->andWhere(['systemGenerated' => $this->systemGenerated]);
        }

        if ($this->enabled !== null) {
            $this->andWhere(['enabled' => $this->enabled]);
        }

        if ($this->activeNow) {
            $now = Db::prepareDateForDb(new DateTime());
            $this->andWhere(['or', ['startDate' => null], ['<=', 'startDate', $now]]);
            $this->andWhere(['or', ['endDate' => null], ['>=', 'endDate', $now]]);
        }

        return parent::prepare($builder);
    }
}

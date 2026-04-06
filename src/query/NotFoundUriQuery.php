<?php

namespace newism\notfoundredirects\query;

use craft\db\Query;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\NotFoundUri;
use yii\db\Expression;

class NotFoundUriQuery extends Query
{
    public static function find(): static
    {
        return new static();
    }

    public ?int $id = null;
    public ?int $siteId = null;
    public ?bool $handled = null;
    public ?string $search = null;
    public bool $withReferrerCount = false;

    public function one($db = null): mixed
    {
        $row = parent::one($db);
        return $row ? NotFoundUri::fromDbRow($row) : null;
    }

    public function populate($rows): array
    {
        return array_map(NotFoundUri::fromDbRow(...), $rows);
    }

    public function prepare($builder)
    {
        $this->from(Table::NOT_FOUND_URIS);

        if ($this->withReferrerCount) {
            $referrerCountSubquery = (new Query())
                ->select([new Expression('COUNT(*)')])
                ->from(Table::REFERRERS)
                ->where(new Expression(Table::REFERRERS . '.[[notFoundId]] = ' . Table::NOT_FOUND_URIS . '.[[id]]'));

            $this->addSelect([Table::NOT_FOUND_URIS . '.*', 'referrerCount' => $referrerCountSubquery]);
        }

        if ($this->id !== null) {
            $this->andWhere([Table::NOT_FOUND_URIS . '.[[id]]' => $this->id]);
        }

        if ($this->siteId !== null) {
            $this->andWhere(['siteId' => $this->siteId]);
        }

        if ($this->handled !== null) {
            $this->andWhere(['handled' => $this->handled]);
        }

        if ($this->search !== null) {
            $this->andWhere(['like', 'uri', $this->search]);
        }

        return parent::prepare($builder);
    }
}

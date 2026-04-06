<?php

namespace newism\notfoundredirects\query;

use craft\db\Query;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\models\Note;

class NoteQuery extends Query
{
    public static function find(): static
    {
        return new static();
    }

    public ?int $redirectId = null;

    public function one($db = null): mixed
    {
        $row = parent::one($db);
        return $row ? Note::fromDbRow($row) : null;
    }

    public function populate($rows): array
    {
        return array_map(Note::fromDbRow(...), $rows);
    }

    public function prepare($builder)
    {
        $this->from(Table::NOTES);

        if ($this->redirectId !== null) {
            $this->andWhere(['redirectId' => $this->redirectId]);
        }

        return parent::prepare($builder);
    }
}

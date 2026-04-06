<?php

namespace newism\notfoundredirects\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;

class Note extends Model
{
    public ?int $id = null;
    public ?int $redirectId = null;
    public ?string $note = null;
    public bool $systemGenerated = false;
    public ?int $createdById = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['note'], 'required'],
            [['redirectId'], 'required'],
            [['redirectId', 'createdById'], 'integer'],
            [['systemGenerated'], 'boolean'],
            [['note'], 'string'],
        ];
    }

    // ── Related Objects ────────────────────────────────────────────────

    private ?User $_createdBy = null;

    public function getCreatedBy(): ?User
    {
        if (isset($this->_createdBy)) {
            return $this->_createdBy;
        }

        if (!isset($this->createdById)) {
            return null;
        }

        $this->_createdBy = Craft::$app->getUsers()->getUserById($this->createdById);

        return $this->_createdBy;
    }

    public function setCreatedBy(?User $user): void
    {
        $this->_createdBy = $user;
    }

    // ── Factory ────────────────────────────────────────────────────────

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

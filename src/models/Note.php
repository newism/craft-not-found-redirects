<?php

namespace newism\notfoundredirects\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
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

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int) $row['id'];
        $model->redirectId = (int) $row['redirectId'];
        $model->note = $row['note'];
        $model->systemGenerated = filter_var($row['systemGenerated'], FILTER_VALIDATE_BOOLEAN);
        $model->createdById = $row['createdById'] ? (int) $row['createdById'] : null;
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;
        $model->uid = $row['uid'] ?? null;

        return $model;
    }
}

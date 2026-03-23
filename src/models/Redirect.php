<?php

namespace newism\notfoundredirects\models;

use Craft;
use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Statusable;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use Illuminate\Support\Collection;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\NotFoundRedirects;

class Redirect extends Model implements Chippable, Statusable, CpEditable
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EXPIRED = 'expired';

    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $from = null;
    public ?string $to = null;
    public string $toType = 'url';
    public ?int $toElementId = null;
    public int $statusCode = 302;
    public int $priority = 0;
    public bool $enabled = true;
    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;
    public bool $systemGenerated = false;
    public ?int $elementId = null;
    public ?int $createdById = null;
    public int $hitCount = 0;
    public ?DateTime $hitLastTime = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['from'], 'required'],
            [['to'], 'required', 'when' => fn() => $this->statusCode !== 410 && $this->toType !== 'entry'],
            [['toElementId'], 'required', 'when' => fn() => $this->statusCode !== 410 && $this->toType === 'entry'],
            [['toType'], 'in', 'range' => ['url', 'entry']],
            [['siteId', 'statusCode', 'priority', 'hitCount', 'toElementId'], 'integer'],
            [['enabled', 'systemGenerated'], 'boolean'],
            [['statusCode'], 'in', 'range' => [301, 302, 307, 410]],
            [['from', 'to'], 'string', 'max' => 2000],
            [['startDate', 'endDate'], 'safe'],
        ];
    }

    // ── Chippable ──────────────────────────────────────────────────────

    public static function get(string|int $id): ?static
    {
        return NotFoundRedirects::getInstance()->redirects->findById((int) $id); // @phpstan-ignore return.type
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getUiLabel(): string
    {
        return Uri::display($this->from);
    }

    // ── Statusable ─────────────────────────────────────────────────────

    public static function statuses(): array
    {
        return [
            self::STATUS_ENABLED => ['label' => 'Enabled', 'color' => Color::Teal],
            self::STATUS_DISABLED => ['label' => 'Disabled', 'color' => Color::Rose],
            self::STATUS_SCHEDULED => ['label' => 'Scheduled', 'color' => Color::Orange],
            self::STATUS_EXPIRED => ['label' => 'Expired', 'color' => Color::Gray],
        ];
    }

    public function getStatus(): ?string
    {
        if (!$this->enabled) {
            return self::STATUS_DISABLED;
        }

        return $this->_status();
    }

    /**
     * @return self::STATUS_ENABLED|self::STATUS_SCHEDULED|self::STATUS_EXPIRED
     */
    private function _status(): string
    {
        $now = DateTimeHelper::now();
        return match (true) {
            $this->startDate && $this->startDate > $now => self::STATUS_SCHEDULED,
            $this->endDate && $this->endDate <= $now => self::STATUS_EXPIRED,
            default => self::STATUS_ENABLED,
        };
    }

    // ── Related Objects ────────────────────────────────────────────────

    private ?User $_createdBy = null;
    private ?ElementInterface $_element = null;

    /**
     * Returns the user who created this redirect, if known.
     */
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

    /**
     * Returns the source element for auto-created redirects, if it still exists.
     */
    public function getElement(): ?ElementInterface
    {
        if (isset($this->_element)) {
            return $this->_element;
        }

        if (!isset($this->elementId)) {
            return null;
        }

        $this->_element = Craft::$app->getElements()->getElementById($this->elementId);

        return $this->_element;
    }

    public function setElement(?ElementInterface $element): void
    {
        $this->_element = $element;
    }

    /**
     * Returns the destination entry for entry-type redirects, if it still exists.
     */
    private ?ElementInterface $_toElement = null;

    public function getToElement(): ?ElementInterface
    {
        if (isset($this->_toElement)) {
            return $this->_toElement;
        }

        if (!isset($this->toElementId)) {
            return null;
        }

        $this->_toElement = Craft::$app->getElements()->getElementById($this->toElementId);

        return $this->_toElement;
    }

    public function setToElement(?ElementInterface $element): void
    {
        $this->_toElement = $element;
    }

    /**
     * Returns all notes for this redirect.
     *
     * @return Collection<int, Note>
     */
    public function getNotes(): Collection
    {
        if (!$this->id) {
            return Collection::empty();
        }

        return NotFoundRedirects::getInstance()->notes->findByRedirectId($this->id);
    }

    // ── CpEditable ─────────────────────────────────────────────────────

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $this->id) : null;
    }

    // ── Factory ────────────────────────────────────────────────────────

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int) $row['id'];
        $model->siteId = $row['siteId'] ? (int) $row['siteId'] : null;
        $model->from = $row['from'];
        $model->to = $row['to'];
        $model->toType = $row['toType'] ?? 'url';
        $model->toElementId = isset($row['toElementId']) ? (int) $row['toElementId'] : null;
        $model->statusCode = (int) $row['statusCode'];
        $model->priority = (int) $row['priority'];
        $model->enabled = filter_var($row['enabled'], FILTER_VALIDATE_BOOLEAN);
        $model->startDate = DateTimeHelper::toDateTime($row['startDate']) ?: null;
        $model->endDate = DateTimeHelper::toDateTime($row['endDate']) ?: null;
        $model->systemGenerated = filter_var($row['systemGenerated'], FILTER_VALIDATE_BOOLEAN);
        $model->elementId = $row['elementId'] ? (int) $row['elementId'] : null;
        $model->createdById = $row['createdById'] ? (int) $row['createdById'] : null;
        $model->hitCount = (int) $row['hitCount'];
        $model->hitLastTime = DateTimeHelper::toDateTime($row['hitLastTime']) ?: null;
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;
        $model->uid = $row['uid'];

        return $model;
    }
}

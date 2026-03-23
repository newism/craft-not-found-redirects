<?php

namespace newism\notfoundredirects\models;

use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\Model;
use craft\base\Statusable;
use craft\enums\Color;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\NotFoundRedirects;

class NotFoundUri extends Model implements Chippable, Statusable, CpEditable
{
    public const STATUS_HANDLED = 'handled';
    public const STATUS_UNHANDLED = 'unhandled';

    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $uri = null;
    public ?string $fullUrl = null;
    public int $hitCount = 1;
    public ?DateTime $hitLastTime = null;
    public bool $handled = false;
    public ?int $redirectId = null;
    public ?string $source = null;
    public int $referrerCount = 0;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['uri', 'siteId'], 'required'],
            [['siteId', 'hitCount', 'redirectId', 'referrerCount'], 'integer'],
            [['handled'], 'boolean'],
            [['uri', 'fullUrl'], 'string', 'max' => 2000],
        ];
    }

    // ── Chippable ──────────────────────────────────────────────────────

    public static function get(string|int $id): ?static
    {
        return NotFoundRedirects::getInstance()->notFound->findById((int) $id); // @phpstan-ignore return.type
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getUiLabel(): string
    {
        return Uri::display($this->uri);
    }

    // ── Statusable ─────────────────────────────────────────────────────

    public static function statuses(): array
    {
        return [
            self::STATUS_HANDLED => ['label' => 'Handled', 'color' => Color::Teal],
            self::STATUS_UNHANDLED => ['label' => 'Unhandled', 'color' => Color::Rose],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->handled ? self::STATUS_HANDLED : self::STATUS_UNHANDLED;
    }

    // ── CpEditable ─────────────────────────────────────────────────────

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('not-found-redirects/404s/detail/' . $this->id) : null;
    }

    // ── Factory ────────────────────────────────────────────────────────

    public static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int) $row['id'];
        $model->siteId = (int) $row['siteId'];
        $model->uri = $row['uri'];
        $model->fullUrl = $row['fullUrl'];
        $model->hitCount = (int) $row['hitCount'];
        $model->hitLastTime = DateTimeHelper::toDateTime($row['hitLastTime']) ?: null;
        $model->handled = filter_var($row['handled'], FILTER_VALIDATE_BOOLEAN);
        $model->redirectId = $row['redirectId'] ? (int) $row['redirectId'] : null;
        $model->source = $row['source'] ?? null;
        $model->referrerCount = (int) ($row['referrerCount'] ?? 0); // virtual column from subquery
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']) ?: null;
        $model->uid = $row['uid'];

        return $model;
    }
}

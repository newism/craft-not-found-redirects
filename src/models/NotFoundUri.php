<?php

namespace newism\notfoundredirects\models;

use Craft;
use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\Model;
use craft\base\Statusable;
use craft\enums\Color;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use newism\notfoundredirects\query\NotFoundUriQuery;
use newism\notfoundredirects\query\ReferrerQuery;

class NotFoundUri extends Model implements Chippable, Statusable, CpEditable
{
    public const STATUS_HANDLED = 'handled';
    public const STATUS_UNHANDLED = 'unhandled';

    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $uri = null;
    public int $hitCount = 1;
    public ?DateTime $hitLastTime = null;
    public bool $handled = false;
    public ?int $redirectId = null;
    public ?string $source = null;
    public int $referrerCount = 0;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?array $_referrers = null;

    public function getReferrers(): array
    {
        if ($this->_referrers !== null) {
            return $this->_referrers;
        }

        if (!$this->id) {
            return $this->_referrers = [];
        }

        $referrerQuery = ReferrerQuery::find();
        $referrerQuery->notFoundId = $this->id;
        return $this->_referrers = $referrerQuery->all();
    }

    public function setReferrers(array $referrers): void
    {
        $this->_referrers = array_map(
            fn($referrer) => $referrer instanceof Referrer ? $referrer : new Referrer($referrer),
            $referrers,
        );
    }

    protected function defineRules(): array
    {
        return [
            [['uri', 'siteId'], 'required'],
            [['siteId', 'hitCount', 'redirectId', 'referrerCount'], 'integer'],
            [['handled'], 'boolean'],
            [['uri'], 'string', 'max' => 500],
        ];
    }

    public function extraFields(): array
    {
        return [
            ... parent::extraFields(),
            'referrers',
        ];
    }

    // ── Chippable ──────────────────────────────────────────────────────

    public static function get(string|int $id): ?static
    {
        $query = NotFoundUriQuery::find();
        $query->id = (int)$id;
        $query->withReferrerCount = true;
        return $query->one();
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getUiLabel(): string
    {
        return $this->uri ?: '/';
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

    /**
     * Create from a Retour DB row (retour_stats table).
     */
    public static function fromRetourDbRow(array $row): self
    {
        $model = new self();
        $model->siteId = $row['siteId'] ?: Craft::$app->getSites()->getPrimarySite()->id;
        $model->uri = $row['redirectSrcUrl'];
        $model->hitCount = (int)$row['hitCount'] ?: 0;
        $model->hitLastTime = DateTimeHelper::toDateTime($row['hitLastTime']) ?: new DateTime();
        $model->handled = (bool)$row['handledByRetour'];
        $model->source = 'retour-import';
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']);
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']);

        return $model;
    }

    /**
     * Create from a Retour CSV export row.
     */
    public static function fromRetourCsvRow(array $row): self
    {
        $model = new self();
        $model->siteId = $row['Site ID'] ?: Craft::$app->getSites()->getPrimarySite()->id;
        $model->uri = $row['404 File Not Found URL'];
        $model->hitCount = (int)$row['Hits'] ?: 0;
        $model->hitLastTime = DateTimeHelper::toDateTime($row['Last Hit']);
        $model->handled = filter_var($row['Handled'], FILTER_VALIDATE_BOOLEAN);
        $model->source = 'retour-import';
        $model->dateCreated = $model->hitLastTime;
        $model->dateUpdated = $model->hitLastTime;

        return $model;
    }

    /**
     * Create from a native CSV row (attribute-name keys, after label remap).
     * Handles empty→null coercion, then delegates to fromJsonObject.
     */
    public static function fromCsvRow(array $row, array $labelMap): self
    {
        $remapped = [];
        foreach ($row as $key => $value) {
            $remapped[$labelMap[$key] ?? $key] = $value;
        }
        $row = $remapped;
        $row = array_map(fn($v) => $v === '' ? null : $v, $row);
        $row['referrers'] = json_decode($row['referrers'] ?? 'null', true) ?? [];
        $row['source'] = 'csv-import';

        return self::fromJsonObject($row);
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

    /**
     * Creates a new instance from a JSON-decoded array, e.g. from an API response or deserialized payload.
     * Properties are set via the constructor, which delegates to App::configure(). This ensures all
     * setter methods (e.g. setReferrers()) are invoked via __set() rather than writing directly to attributes.
     */
    public static function fromJsonObject(array $data): self
    {
        return new self($data);
    }

}

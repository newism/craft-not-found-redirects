<?php

namespace newism\notfoundredirects\models;

use Craft;
use craft\base\Actionable;
use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Statusable;
use craft\elements\User;
use craft\enums\Color;
use craft\enums\MenuItemType;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use DateTime;
use newism\notfoundredirects\query\NoteQuery;
use newism\notfoundredirects\query\RedirectQuery;
use newism\notfoundredirects\web\assets\RedirectChipAsset;

class Redirect extends Model implements Actionable, Chippable, Statusable, CpEditable
{
    public const STATUS_LIVE = 'live';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXPIRED = 'expired';

    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $from = null;
    public ?string $to = null;
    public ?string $toType = 'url';
    public ?int $toElementId = null;
    public int $statusCode = 302;
    public int $priority = 0;
    public bool $enabled = true;
    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;
    public bool $regexMatch = false;
    public bool $systemGenerated = false;
    public ?int $elementId = null;
    public ?int $createdById = null;
    public int $hitCount = 0;
    public ?DateTime $hitLastTime = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?array $_notes = null;

    public function getNotes(): array
    {
        if ($this->_notes !== null) {
            return $this->_notes;
        }

        if (!$this->id) {
            return $this->_notes = [];
        }

        $notesQuery = NoteQuery::find();
        $notesQuery->redirectId = $this->id;
        return $this->_notes = $notesQuery->all();
    }

    public function setNotes(array $notes): void
    {
        $this->_notes = array_map(
            fn($note) => $note instanceof Note ? $note : new Note($note),
            $notes,
        );
    }

    protected function defineRules(): array
    {
        return [
            [['from'], 'required'],
            [['to'], 'required', 'when' => fn() => !in_array($this->statusCode, [404, 410]) && $this->toType !== 'entry'],
            [['toElementId'], 'required', 'when' => fn() => !in_array($this->statusCode, [404, 410]) && $this->toType === 'entry'],
            [['toType'], 'in', 'range' => ['url', 'entry']],
            [['siteId', 'statusCode', 'priority', 'hitCount', 'toElementId'], 'integer'],
            [['enabled', 'regexMatch', 'systemGenerated'], 'boolean'],
            [['statusCode'], 'in', 'range' => [301, 302, 307, 404, 410]],
            [['from', 'to'], 'string', 'max' => 500],
            [['startDate', 'endDate'], 'safe'],
        ];
    }

    public function extraFields(): array
    {
        return [
            ... parent::extraFields(),
            'notes',
        ];
    }

    // ── Chippable ──────────────────────────────────────────────────────

    public static function get(string|int $id): ?static
    {
        $query = RedirectQuery::find();
        $query->id = (int)$id;
        return $query->one();
    }

    public function getId(): string|int|null
    {
        return $this->id;
    }

    public function getUiLabel(): string
    {
        return $this->from ?: '/';
    }

    public function getChipHtml(): string
    {
        $id = Html::id('redirect-chip-' . $this->id);
        $view = Craft::$app->getView();
        $view->registerAssetBundle(RedirectChipAsset::class);
        $view->registerJs("new Newism.notFoundRedirects.RedirectChip('#$id');");

        return Cp::chipHtml(
            $this,
            [
                'showActionMenu' => true,
                'showStatus' => true,
                'labelHtml' => Html::tag('craft-element-label', Html::tag('span', $this->getUiLabel(), [
                    'class' => 'label-link',
                ]), [
                    'class' => 'label',
                ]),
                'attributes' => [
                    'id' => $id,
                    'data-controller' => 'redirect-chip',
                    'data-redirect-id' => $this->id,
                    'data-editable' => true,
                    'data-cp-url' => $this->getCpEditUrl(),
                ]
            ]
        );
    }

    // ── Statusable ─────────────────────────────────────────────────────

    public static function statuses(): array
    {
        return [
            self::STATUS_LIVE => ['label' => 'Live', 'color' => Color::Teal],
            self::STATUS_DISABLED => ['label' => 'Disabled', 'color' => Color::Gray],
            self::STATUS_PENDING => ['label' => 'Pending', 'color' => Color::Orange],
            self::STATUS_EXPIRED => ['label' => 'Expired', 'color' => Color::Red],
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
     * @return self::STATUS_LIVE|self::STATUS_PENDING|self::STATUS_EXPIRED
     */
    private function _status(): string
    {
        $now = DateTimeHelper::now();
        return match (true) {
            $this->startDate && $this->startDate > $now => self::STATUS_PENDING,
            $this->endDate && $this->endDate <= $now => self::STATUS_EXPIRED,
            default => self::STATUS_LIVE,
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


    // ── CpEditable ─────────────────────────────────────────────────────

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('not-found-redirects/redirects/edit/' . $this->id) : null;
    }

    // ── Actionable ──────────────────────────────────────────────────────

    public function getActionMenuItems(): array
    {
        $view = Craft::$app->getView();
        $editId = sprintf('action-edit-%s', mt_rand());

        $view->registerJsWithVars(fn($id, $redirectId) => <<<JS
$('#' + $id).on('activate', () => {
    const slideout = new Craft.CpScreenSlideout(
        'not-found-redirects/redirects/edit?redirectId=' + $redirectId
    );
    slideout.on('submit', () => {
        document.dispatchEvent(new CustomEvent('notFoundRedirects:redirectSaved', {
            detail: { redirectId: $redirectId },
        }))
    });
});
JS, [
            $view->namespaceInputId($editId),
            $this->id,
        ]);

        $deleteId = sprintf('action-delete-%s', mt_rand());

        $view->registerJsWithVars(fn($id, $redirectId) => <<<JS
$('#' + $id).on('activate', () => {
    if (!confirm(Craft.t('not-found-redirects', 'Are you sure you want to delete this redirect?'))) return;
    Craft.sendActionRequest('POST', 'not-found-redirects/redirects/delete', {
        data: { id: $redirectId },
    }).then(() => {
        Craft.cp.displayNotice(Craft.t('not-found-redirects', 'Redirect deleted.'));
        document.dispatchEvent(new CustomEvent('notFoundRedirects:redirectDeleted'));
    }).catch(() => {
        Craft.cp.displayError(Craft.t('not-found-redirects', 'Could not delete redirect.'));
    });
});
JS, [
            $view->namespaceInputId($deleteId),
            $this->id,
        ]);

        return [
            [
                'type' => MenuItemType::Button,
                'id' => $editId,
                'icon' => 'pencil',
                'label' => Craft::t('app', 'Edit'),
                'attributes' => [
                    'data-edit-action' => true,
                ],
            ],
            [
                'type' => MenuItemType::Button,
                'id' => $deleteId,
                'icon' => 'xmark',
                'label' => Craft::t('app', 'Delete'),
                'destructive' => true,
                'showInChips' => true,
            ],
        ];
    }

    // ── Factory ────────────────────────────────────────────────────────

    /**
     * Create from a Retour DB row (retour_static_redirects table).
     */
    public static function fromRetourDbRow(array $row): self
    {
        $model = new self();
        $model->from = $row['redirectSrcUrl'];
        $model->to = $row['redirectDestUrl'];
        $model->regexMatch = $row['redirectMatchType'] === 'regexmatch';
        $model->statusCode = $row['redirectHttpCode'];
        $model->siteId = $row['siteId'] ?: Craft::$app->getSites()->getPrimarySite()->id;
        $model->hitCount = (int)$row['hitCount'];
        $model->hitLastTime = $row['hitLastTime'] ? DateTimeHelper::toDateTime($row['hitLastTime']) : null;
        $model->priority = (int)$row['priority'];
        $model->enabled = (bool)$row['enabled'];
        $model->dateCreated = DateTimeHelper::toDateTime($row['dateCreated']);
        $model->dateUpdated = DateTimeHelper::toDateTime($row['dateUpdated']);
        $model->elementId = $row['associatedElementId'] ?: null;
        return $model;
    }

    /**
     * Create from a Retour CSV export row.
     */
    public static function fromRetourCsvRow(array $row): self
    {
        $model = new self();
        $model->from = $row['Legacy URL Pattern'];
        $model->to = $row['Redirect To'];
        $model->regexMatch = $row['Match Type'] === 'regexmatch';
        $model->statusCode = (int)$row['HTTP Status'];
        $model->siteId = (int)($row['Site ID'] ?: Craft::$app->getSites()->getPrimarySite()->id);
        $model->hitCount = (int)$row['Hits'];
        $model->hitLastTime = $row['Last Hit'] ? DateTimeHelper::toDateTime($row['Last Hit']) : null;
        $model->priority = (int)$row['Priority'];
        $model->enabled = true;
        // Assume the date created was the hitLastTime
        $model->dateCreated = $model->hitLastTime;
        $model->dateUpdated = $model->hitLastTime;
        return $model;
    }

    /**
     * Create from a native CSV row (attribute-name keys, after label remap).
     * Handles empty→null coercion, then delegates to fromJsonObject.
     * Craft's setAttributes handles bool/date/int coercion via Typecast.
     */
    public static function fromCsvRow(array $row, array $labelMap): self
    {
        $remapped = [];
        foreach ($row as $key => $value) {
            $remapped[$labelMap[$key] ?? $key] = $value;
        }
        $row = $remapped;
        $row = array_map(fn($v) => $v === '' ? null : $v, $row);
        $row['notes'] = json_decode($row['notes'] ?? 'null', true) ?? [];

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
     * setter methods (e.g. setNotes()) are invoked via __set() rather than writing directly to attributes.
     */
    public static function fromJsonObject(array $data): self
    {
        return new self($data);
    }
}

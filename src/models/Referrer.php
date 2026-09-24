<?php

namespace newism\notfoundredirects\models;

use craft\base\Model;
use DateTime;

class Referrer extends Model
{
    public ?int $id = null;
    public ?int $notFoundId = null;
    public ?string $referrer = null;
    public int $hitCount = 1;
    public ?DateTime $hitLastTime = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    protected function defineRules(): array
    {
        return [
            [['notFoundId', 'referrer'], 'required'],
            [['notFoundId', 'hitCount'], 'integer'],
            [['referrer'], 'string', 'max' => 500],
        ];
    }

    /**
     * Normalise a raw `Referer` header for storage.
     *
     * Referrers are arbitrary client-supplied input, so drop invalid UTF-8 byte sequences and
     * control characters before truncating to the varchar(500) column width. Truncation is
     * character-based (`mb_substr`) to match `varchar` semantics in both MySQL and Postgres, and
     * so a multibyte sequence is never split mid-codepoint — a byte-based cut would produce
     * invalid UTF-8 that the database then rejects.
     *
     * Returns null when nothing usable remains.
     */
    public static function normalize(?string $referrer): ?string
    {
        if ($referrer === null || $referrer === '') {
            return null;
        }

        // Converting UTF-8 → UTF-8 substitutes any invalid byte sequences
        $referrer = mb_convert_encoding($referrer, 'UTF-8', 'UTF-8');
        $referrer = preg_replace('/[\x00-\x1F\x7F]+/u', '', $referrer) ?? $referrer;
        $referrer = mb_substr(trim($referrer), 0, 500);

        return $referrer !== '' ? $referrer : null;
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
}

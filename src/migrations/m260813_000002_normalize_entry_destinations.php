<?php

namespace newism\notfoundredirects\migrations;

use Craft;
use craft\base\Element;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use newism\notfoundredirects\db\Table;

/**
 * Normalizes the cached `to` value of entry-type redirects to the destination
 * site's relative URI.
 *
 * The convention: `to` holds the entry's URI in the DESTINATION site's
 * coordinate system ('' for the homepage — never the literal `__home__`
 * token), and the destination site itself lives in `toElementSiteId`. The
 * runtime resolves the URI against that site, which works for absolute,
 * root-relative, and subfolder site base URLs alike.
 *
 * Earlier code cached other shapes that this cleans up:
 *   - literal `__home__` (written by the UpdateDestinationUris job)
 *   - absolute or root-relative URLs for cross-site destinations (written
 *     while `to` carried the site identity in the string)
 */
class m260813_000002_normalize_entry_destinations extends Migration
{
    public function safeUp(): bool
    {
        // Literal homepage tokens (any redirect type) → ''
        $this->update(
            Table::REDIRECTS,
            ['to' => ''],
            ['to' => Element::HOMEPAGE_URI],
            [],
            false,
        );

        // Entry-type rows: recache `to` from the destination site's current URI
        $rows = (new Query())
            ->select(['id', 'siteId', 'to', 'toElementId', 'toElementSiteId'])
            ->from(Table::REDIRECTS)
            ->where(['toType' => 'entry'])
            ->andWhere(['not', ['toElementId' => null]])
            ->all($this->db);

        if (!$rows) {
            return true;
        }

        $elementIds = array_values(array_unique(array_map(static fn($r) => (int)$r['toElementId'], $rows)));

        // "elementId:siteId" => uri
        $uris = [];
        $siteRows = (new Query())
            ->select(['elementId', 'siteId', 'uri'])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $elementIds])
            ->all($this->db);
        foreach ($siteRows as $sr) {
            $uris[$sr['elementId'] . ':' . $sr['siteId']] = $sr['uri'];
        }

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($rows as $row) {
            $destSiteId = $row['toElementSiteId'] ?? $row['siteId'] ?? $primarySiteId;
            $uri = $uris[$row['toElementId'] . ':' . $destSiteId] ?? null;

            // Entry no longer exists on that site — keep the existing cached
            // value; it is still the best-known fallback.
            if ($uri === null) {
                continue;
            }

            $newTo = $uri === Element::HOMEPAGE_URI ? '' : ltrim($uri, '/');
            if ($newTo !== (string)$row['to']) {
                $this->update(Table::REDIRECTS, ['to' => $newTo], ['id' => $row['id']], [], false);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Data normalization — nothing to restore.
        return true;
    }
}

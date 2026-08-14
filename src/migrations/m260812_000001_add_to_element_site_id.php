<?php

namespace newism\notfoundredirects\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use newism\notfoundredirects\db\Table;

/**
 * Adds a `toElementSiteId` column to the redirects table so entry-type
 * destinations can point at an entry on a *specific* site.
 *
 * An element ID is globally unique, but a single entry has one row per site in
 * `elements_sites`, each with its own URI and (because each site has its own
 * base URL) its own domain. Storing the chosen site lets us resolve the entry
 * against the correct site so `getUrl()` yields the intended cross-site URL.
 *
 * The column is nullable and all consuming code falls back gracefully when it
 * is empty (resolving against the redirect's own site, then the primary site),
 * so nothing breaks for rows this migration cannot backfill.
 */
class m260812_000001_add_to_element_site_id extends Migration
{
    public function safeUp(): bool
    {
        $table = Table::REDIRECTS;

        if (!$this->db->columnExists($table, 'toElementSiteId')) {
            $this->addColumn($table, 'toElementSiteId', $this->integer()->null()->defaultValue(null)->after('toElementId'));
            $this->createIndex(null, $table, ['toElementSiteId'], false);
        }

        // Backfill existing entry-type redirects with a best-effort site.
        $this->backfill($table);

        // Add the FK last, once values are guaranteed valid. SET NULL on delete
        // so removing a site downgrades the redirect to the graceful fallback
        // rather than deleting the redirect itself.
        if (!$this->foreignKeyExists($table, 'toElementSiteId')) {
            $this->addForeignKey(
                null,
                $table,
                ['toElementSiteId'],
                CraftTable::SITES,
                ['id'],
                'SET NULL',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = Table::REDIRECTS;

        if ($this->db->columnExists($table, 'toElementSiteId')) {
            // Drop the FK (auto-named) before the column.
            $rawTableName = $this->db->getSchema()->getRawTableName($table);
            foreach ($this->db->getSchema()->getTableSchema($rawTableName)->foreignKeys as $name => $fk) {
                if (array_key_exists('toElementSiteId', $fk)) {
                    $this->dropForeignKey($name, $table);
                }
            }
            $this->dropColumn($table, 'toElementSiteId');
        }

        return true;
    }

    /**
     * Populate `toElementSiteId` for existing entry-type redirects.
     *
     * Heuristic per redirect:
     *   1. The redirect's own siteId, if the entry has a row on that site.
     *   2. The primary site, if the entry has a row there.
     *   3. The entry's lowest site id that has a URI (else lowest site id).
     */
    private function backfill(string $table): void
    {
        $rows = (new Query())
            ->select(['id', 'siteId', 'toElementId'])
            ->from($table)
            ->where(['toType' => 'entry'])
            ->andWhere(['not', ['toElementId' => null]])
            ->andWhere(['toElementSiteId' => null])
            ->all($this->db);

        if (!$rows) {
            return;
        }

        $elementIds = array_values(array_unique(array_map(static fn($r) => (int)$r['toElementId'], $rows)));

        // elementId => [ siteId => uri ]
        $siteRows = (new Query())
            ->select(['elementId', 'siteId', 'uri'])
            ->from(CraftTable::ELEMENTS_SITES)
            ->where(['elementId' => $elementIds])
            ->all($this->db);

        $sitesByElement = [];
        foreach ($siteRows as $sr) {
            $sitesByElement[(int)$sr['elementId']][(int)$sr['siteId']] = $sr['uri'];
        }

        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($rows as $r) {
            $sites = $sitesByElement[(int)$r['toElementId']] ?? [];
            if (!$sites) {
                continue;
            }

            $redirectSiteId = $r['siteId'] !== null ? (int)$r['siteId'] : null;

            if ($redirectSiteId !== null && array_key_exists($redirectSiteId, $sites)) {
                $chosen = $redirectSiteId;
            } elseif (array_key_exists($primarySiteId, $sites)) {
                $chosen = $primarySiteId;
            } else {
                $withUri = array_filter($sites, static fn($uri) => $uri !== null && $uri !== '');
                $pool = $withUri ?: $sites;
                $chosen = (int)min(array_keys($pool));
            }

            $this->update($table, ['toElementSiteId' => $chosen], ['id' => $r['id']]);
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $rawTableName = $this->db->getSchema()->getRawTableName($table);
        $schema = $this->db->getSchema()->getTableSchema($rawTableName);
        if (!$schema) {
            return false;
        }
        foreach ($schema->foreignKeys as $fk) {
            if (array_key_exists($column, $fk)) {
                return true;
            }
        }
        return false;
    }
}

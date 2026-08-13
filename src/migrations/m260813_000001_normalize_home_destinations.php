<?php

namespace newism\notfoundredirects\migrations;

use craft\base\Element;
use craft\db\Migration;
use newism\notfoundredirects\db\Table;

/**
 * Normalizes cached `to` values holding the literal `__home__` homepage token.
 *
 * The convention is that a homepage destination is cached as '' (and rendered
 * as '/'), but the UpdateDestinationUris job used to write the element's raw
 * URI — `__home__` for homepage entries — into `to`, which the cached-fallback
 * redirect path would send visitors to verbatim (a 404).
 */
class m260813_000001_normalize_home_destinations extends Migration
{
    public function safeUp(): bool
    {
        $this->update(
            Table::REDIRECTS,
            ['to' => ''],
            ['to' => Element::HOMEPAGE_URI],
            [],
            false,
        );

        return true;
    }

    public function safeDown(): bool
    {
        // Data normalization — nothing to restore.
        return true;
    }
}

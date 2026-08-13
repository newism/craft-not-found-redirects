<?php

namespace newism\notfoundredirects\jobs;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\RedirectQuery;

/**
 * Updates the cached `to` URI on entry-type redirects pointing to a given element.
 * Pushed after element save or URI update to keep destinations current.
 *
 * Only redirects whose destination site matches the saved site are updated —
 * a single entry has one URI per site, so a save on site A must not overwrite
 * the cached destination of a redirect targeting the entry on site B.
 */
class UpdateDestinationUris extends BaseJob
{
    public int $elementId;
    public int $siteId;
    public string $newUri;

    protected function defaultDescription(): ?string
    {
        return "Updating redirect destinations for element #{$this->elementId}";
    }

    public function execute($queue): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        // The homepage URI is stored as `__home__` — cache it as '' like saveRedirect() does
        $newUri = $this->newUri === Element::HOMEPAGE_URI ? '' : Uri::strip($this->newUri);

        // Find all redirects pointing to this element
        $query = RedirectQuery::find();
        $query->andWhere(['toElementId' => $this->elementId, 'toType' => 'entry']);
        /** @var Redirect[] $redirects */
        $redirects = $query->all();

        if (!$redirects) {
            return;
        }

        $noteService = NotFoundRedirects::getInstance()->getNoteService();
        $updated = 0;

        foreach ($redirects as $redirect) {
            // Redirects without a stored destination site resolve against their own site
            $destSiteId = $redirect->toElementSiteId ?? $redirect->siteId ?? $primarySiteId;
            if ($destSiteId !== $this->siteId) {
                continue;
            }

            // Match saveRedirect()'s normalization: `to` holds the entry's URI in the
            // destination site's coordinate system — the site itself lives in
            // toElementSiteId, so no URL building (and no web-vs-console ambiguity).
            $newTo = $newUri;

            if (strcasecmp($redirect->to ?? '', $newTo) === 0) {
                continue;
            }

            $db->createCommand()->update(
                Table::REDIRECTS,
                ['to' => $newTo, 'dateUpdated' => $now],
                ['id' => $redirect->id],
            )->execute();

            $noteService->addNote(
                $redirect->id,
                'Destination URI updated: ' . $this->displayTo($redirect->to) . ' → ' . $this->displayTo($newTo),
                systemGenerated: true,
            );
            $updated++;
        }

        if ($updated > 0) {
            Craft::info("Updated {$updated} redirect destination(s) for element #{$this->elementId} (site #{$this->siteId})", NotFoundRedirects::LOG);
        }
    }

    private function displayTo(?string $to): string
    {
        if ($to !== null && preg_match('#^https?://#i', $to)) {
            return $to;
        }

        return '/' . Uri::strip($to);
    }
}

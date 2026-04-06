<?php

namespace newism\notfoundredirects\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;
use newism\notfoundredirects\db\Table;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\models\Redirect;
use newism\notfoundredirects\NotFoundRedirects;
use newism\notfoundredirects\query\RedirectQuery;

/**
 * Updates the cached `to` URI on all entry-type redirects pointing to a given element.
 * Pushed after element save or URI update to keep destinations current.
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
        $newUri = Uri::strip($this->newUri);

        // Find all redirects pointing to this element
        $query = RedirectQuery::find();
        $query->andWhere(['toElementId' => $this->elementId, 'toType' => 'entry']);
        $redirects = $query->all();

        if (!$redirects) {
            return;
        }

        // Update the cached `to` URI
        $db->createCommand()->update(
            Table::REDIRECTS,
            ['to' => $newUri, 'dateUpdated' => $now],
            ['toElementId' => $this->elementId, 'toType' => 'entry'],
        )->execute();

        // Add system notes to affected redirects where the URI actually changed
        $noteService = NotFoundRedirects::getInstance()->getNoteService();
        foreach ($redirects as $redirect) {
            if (strcasecmp($redirect->to, $newUri) !== 0) {
                $noteService->addNote(
                    $redirect->id,
                    "Destination URI updated: /{$redirect->to} → /{$newUri}",
                    systemGenerated: true,
                );
            }
        }

        Craft::info("Updated " . count($redirects) . " redirect destination(s) for element #{$this->elementId} → /{$newUri}", NotFoundRedirects::LOG);
    }
}

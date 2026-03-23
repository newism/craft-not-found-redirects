<?php

namespace newism\notfoundredirects\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use newism\notfoundredirects\helpers\Uri;
use newism\notfoundredirects\NotFoundRedirects;

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
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $newUri = Uri::strip($this->newUri);

        // Find all redirects pointing to this element
        $redirects = (new Query())
            ->select(['id', 'to'])
            ->from('{{%notfoundredirects_redirects}}')
            ->where(['toElementId' => $this->elementId, 'toType' => 'entry'])
            ->all();

        if (empty($redirects)) {
            return;
        }

        // Update the cached `to` URI
        $db->createCommand()->update(
            '{{%notfoundredirects_redirects}}',
            ['to' => $newUri, 'dateUpdated' => $now],
            ['toElementId' => $this->elementId, 'toType' => 'entry'],
        )->execute();

        // Add system notes to affected redirects where the URI actually changed
        $noteService = NotFoundRedirects::getInstance()->notes;
        foreach ($redirects as $redirect) {
            if (strcasecmp($redirect['to'], $newUri) !== 0) {
                $noteService->addNote(
                    (int) $redirect['id'],
                    "Destination URI updated: /{$redirect['to']} → /{$newUri}",
                    systemGenerated: true,
                );
            }
        }

        Craft::info("Updated " . count($redirects) . " redirect destination(s) for element #{$this->elementId} → /{$newUri}", NotFoundRedirects::LOG);
    }
}

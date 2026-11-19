<?php

namespace justinholtweb\freelink\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use justinholtweb\freelink\Plugin;

/**
 * Rebuilds the link index from field values, in batches.
 *
 * Pushed by the 5.3 migration and the "Rebuild index" action. Safe to run again at any time.
 */
class IndexLinks extends BaseBatchedJob
{
    public int $batchSize = 50;

    protected function loadData(): Batchable
    {
        return new QueryBatcher(Plugin::getInstance()->linkIndex->elementsToIndexQuery());
    }

    /**
     * @param array{id: int|string, type: string} $item
     */
    protected function processItem(mixed $item): void
    {
        try {
            Plugin::getInstance()->linkIndex->indexElementId((int)$item['id'], (string)$item['type']);
        } catch (\Throwable $e) {
            Craft::warning("FreeLink couldn't index element {$item['id']}: {$e->getMessage()}", __METHOD__);
        }
    }

    protected function after(): void
    {
        Plugin::getInstance()->linkIndex->markBuilt();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('freelink', 'Indexing FreeLink links');
    }
}

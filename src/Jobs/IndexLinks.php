<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Jobs;

use CraftCms\Cms\Queue\BatchedJob;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Rebuilds the link index from field values, in batches.
 *
 * Pushed by the 5.3 migration, the Craft 6 upgrade migration and the "Rebuild index" action. Safe to
 * run again at any time.
 */
class IndexLinks extends BatchedJob
{
    public int $batchSize = 50;

    #[Override]
    protected function getQuery(): Builder
    {
        return app(LinkIndex::class)->elementsToIndexQuery();
    }

    #[Override]
    protected function processItem(mixed $item): void
    {
        $id = (int) (is_object($item) ? $item->id : $item['id']);
        $type = (string) (is_object($item) ? $item->type : $item['type']);

        try {
            app(LinkIndex::class)->indexElementId($id, $type);
        } catch (Throwable $e) {
            Log::warning("FreeLink couldn't index element $id: {$e->getMessage()}");
        }
    }

    #[Override]
    protected function after(): void
    {
        app(LinkIndex::class)->markBuilt();
    }

    #[Override]
    protected function defaultDescription(): string
    {
        return t('Indexing FreeLink links', category: 'freelink');
    }
}

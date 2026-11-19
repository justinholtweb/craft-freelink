<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Services\LinkIndex;

/**
 * Rebuilds the link index from field values.
 */
class LinksReindexCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'freelink:links:reindex';

    #[\Override]
    protected $description = 'Rebuilds the FreeLink link index from field values.';

    #[\Override]
    protected $aliases = ['freelink/links/reindex'];

    public function handle(LinkIndex $index): int
    {
        $this->info('Indexing FreeLink links...');
        $bar = null;

        $count = $index->rebuild(function (int $done, int $total) use (&$bar): void {
            $bar ??= $this->output->createProgressBar($total);
            $bar->setProgress($done);
        });

        $bar?->finish();
        $this->newLine();
        $this->info("Indexed $count element(s).");

        return self::SUCCESS;
    }
}

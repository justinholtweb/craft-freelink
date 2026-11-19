<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Services\LinkIndex;

/**
 * Prints link counts and problems.
 */
class LinksStatusCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'freelink:links:status';

    #[\Override]
    protected $description = 'Prints FreeLink link counts by status.';

    #[\Override]
    protected $aliases = ['freelink/links/status'];

    public function handle(LinkIndex $index): int
    {
        foreach (['all', 'problems', 'ok', 'unchecked'] as $status) {
            $this->line(sprintf('%-10s %d', $status, $index->createQuery(['status' => $status])->count('i.id')));
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Console\Commands\Concerns\RunsMigration;
use JustinHoltWeb\FreeLink\Services\Migrate;

/**
 * Migrates Craft’s own Link field fields to FreeLink.
 */
class MigrateFromCraftLinkCommand extends Command
{
    use CraftCommand;
    use RunsMigration;

    #[\Override]
    protected $signature = 'freelink:migrate:from-craft-link {--f|field= : One field handle; omit for every field} {--b|backup : Back up the database first} {--d|dry-run : Report what would change without writing anything}';

    #[\Override]
    protected $description = 'Migrates Craft’s own Link field fields to FreeLink.';

    #[\Override]
    protected $aliases = ['freelink/migrate/from-craft-link'];

    public function handle(Migrate $migrate): int
    {
        return $this->runMigration('craftlink', $migrate);
    }
}

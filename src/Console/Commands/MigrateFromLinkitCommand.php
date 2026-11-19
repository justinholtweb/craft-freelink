<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Console\Commands\Concerns\RunsMigration;
use JustinHoltWeb\FreeLink\Services\Migrate;

/**
 * Migrates Linkit (Craft 5 only) fields to FreeLink.
 */
class MigrateFromLinkitCommand extends Command
{
    use CraftCommand;
    use RunsMigration;

    #[\Override]
    protected $signature = 'freelink:migrate:from-linkit {--f|field= : One field handle; omit for every field} {--b|backup : Back up the database first} {--d|dry-run : Report what would change without writing anything}';

    #[\Override]
    protected $description = 'Migrates Linkit (Craft 5 only) fields to FreeLink.';

    #[\Override]
    protected $aliases = ['freelink/migrate/from-linkit'];

    public function handle(Migrate $migrate): int
    {
        return $this->runMigration('linkit', $migrate);
    }
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Console\Commands\Concerns\RunsMigration;
use JustinHoltWeb\FreeLink\Services\Migrate;

/**
 * Migrates Typed Link Field (Craft 5 only) fields to FreeLink.
 */
class MigrateFromTypedLinkCommand extends Command
{
    use CraftCommand;
    use RunsMigration;

    #[\Override]
    protected $signature = 'freelink:migrate:from-typed-link {--f|field= : One field handle; omit for every field} {--b|backup : Back up the database first} {--d|dry-run : Report what would change without writing anything}';

    #[\Override]
    protected $description = 'Migrates Typed Link Field (Craft 5 only) fields to FreeLink.';

    #[\Override]
    protected $aliases = ['freelink/migrate/from-typed-link'];

    public function handle(Migrate $migrate): int
    {
        return $this->runMigration('typedlinkfield', $migrate);
    }
}

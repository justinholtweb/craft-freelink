<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\FreeLink\Services\Migrate;

/**
 * Shows migration history and the fields that can be migrated.
 */
class MigrateStatusCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'freelink:migrate:status';

    #[\Override]
    protected $description = 'Shows FreeLink migration history and the fields that can be migrated.';

    #[\Override]
    protected $aliases = ['freelink/migrate/status'];

    public function handle(Migrate $migrate): int
    {
        $available = $migrate->getAvailableMigrations();

        if ($available !== []) {
            $this->line('Available migrations:');
            foreach ($available as $info) {
                $this->line("  - {$info['label']}: {$info['fieldCount']} field(s) detected");
            }
        }

        $migrations = $migrate->getStatus();

        if ($migrations === []) {
            $this->line('No migrations recorded.');

            return self::SUCCESS;
        }

        $this->line('Migration history:');

        foreach ($migrations as $migration) {
            $this->line(sprintf(
                '  [%s] Field ID: %d | Source: %s | Date: %s',
                $migration['status'],
                $migration['fieldId'],
                $migration['sourcePlugin'],
                $migration['dateUpdated'],
            ));
        }

        return self::SUCCESS;
    }
}

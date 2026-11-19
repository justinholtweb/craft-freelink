<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Console\Commands\Concerns;

use JustinHoltWeb\FreeLink\Services\Migrate;

/**
 * The shared body of the `freelink:migrate:from-*` commands.
 */
trait RunsMigration
{
    protected function runMigration(string $source, Migrate $migrate): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backup = (bool) $this->option('backup');
        $field = $this->option('field');

        if ($dryRun) {
            $this->line('=== DRY RUN MODE ===');
        }

        $this->line("Starting migration from $source...");

        $result = $migrate->runMigration($source, is_string($field) && $field !== '' ? $field : null, $dryRun, $backup);

        foreach ($result['log'] as $message) {
            $this->line("  $message");
        }

        if ($result['success']) {
            $this->info($dryRun ? 'Dry run complete. No changes were made.' : 'Migration completed successfully.');

            return self::SUCCESS;
        }

        $this->error('Migration failed. See the log above for details.');

        return self::FAILURE;
    }
}

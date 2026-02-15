<?php

namespace justinholtweb\freelink\console\controllers;

use craft\console\Controller;
use justinholtweb\freelink\Plugin;
use yii\console\ExitCode;

/**
 * Console commands for migrating to FreeLink from other link field plugins.
 */
class MigrateController extends Controller
{
    /**
     * @var string|null Specific field handle to migrate
     */
    public ?string $field = null;

    /**
     * @var bool Create a database backup before migrating
     */
    public bool $backup = false;

    /**
     * @var bool Preview changes without applying them
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID !== 'status') {
            $options[] = 'field';
            $options[] = 'backup';
            $options[] = 'dryRun';
        }

        return $options;
    }

    public function optionAliases(): array
    {
        return [
            'f' => 'field',
            'b' => 'backup',
            'd' => 'dryRun',
        ];
    }

    /**
     * Migrate from Verbb Hyper.
     *
     * php craft freelink/migrate/from-hyper [--field=<handle>] [--backup] [--dry-run]
     */
    public function actionFromHyper(): int
    {
        return $this->_runMigration('hyper');
    }

    /**
     * Migrate from Pressed Digital Linkit.
     *
     * php craft freelink/migrate/from-linkit [--field=<handle>] [--backup] [--dry-run]
     */
    public function actionFromLinkit(): int
    {
        return $this->_runMigration('linkit');
    }

    /**
     * Migrate from Sebastian Lenz Typed Link Field.
     *
     * php craft freelink/migrate/from-typed-link [--field=<handle>] [--backup] [--dry-run]
     */
    public function actionFromTypedLink(): int
    {
        return $this->_runMigration('typedlinkfield');
    }

    /**
     * Migrate from Craft CMS native Link field.
     *
     * php craft freelink/migrate/from-craft-link [--field=<handle>] [--backup] [--dry-run]
     */
    public function actionFromCraftLink(): int
    {
        return $this->_runMigration('craftlink');
    }

    /**
     * Show migration status.
     *
     * php craft freelink/migrate/status
     */
    public function actionStatus(): int
    {
        $migrations = Plugin::getInstance()->migrate->getStatus();

        if (empty($migrations)) {
            $this->stdout("No migrations recorded.\n");

            // Check for available migrations
            $available = Plugin::getInstance()->migrate->getAvailableMigrations();

            if (!empty($available)) {
                $this->stdout("\nAvailable migrations:\n");
                foreach ($available as $info) {
                    $this->stdout("  - {$info['label']}: {$info['fieldCount']} field(s) detected\n");
                }
            }

            return ExitCode::OK;
        }

        $this->stdout("Migration history:\n\n");

        foreach ($migrations as $migration) {
            $this->stdout(sprintf(
                "  [%s] Field ID: %d | Source: %s | Status: %s | Date: %s\n",
                $migration['status'],
                $migration['fieldId'],
                $migration['sourcePlugin'],
                $migration['status'],
                $migration['dateUpdated'],
            ));
        }

        return ExitCode::OK;
    }

    private function _runMigration(string $source): int
    {
        if ($this->dryRun) {
            $this->stdout("=== DRY RUN MODE ===\n\n");
        }

        if ($this->backup) {
            $this->stdout("Database backup will be created before migration.\n");
        }

        $this->stdout("Starting migration from {$source}...\n\n");

        $result = Plugin::getInstance()->migrate->runMigration(
            $source,
            $this->field,
            $this->dryRun,
            $this->backup,
        );

        foreach ($result['log'] as $message) {
            $this->stdout("  {$message}\n");
        }

        $this->stdout("\n");

        if ($result['success']) {
            $this->stdout($this->dryRun
                ? "Dry run complete. No changes were made.\n"
                : "Migration completed successfully.\n"
            );
            return ExitCode::OK;
        }

        $this->stderr("Migration failed. See log above for details.\n");
        return ExitCode::UNSPECIFIED_ERROR;
    }
}

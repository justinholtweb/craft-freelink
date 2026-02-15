<?php

namespace justinholtweb\freelink\services;

use Craft;
use craft\db\Query;
use justinholtweb\freelink\migrators\BaseMigrator;
use justinholtweb\freelink\migrators\CraftLinkMigrator;
use justinholtweb\freelink\migrators\HyperMigrator;
use justinholtweb\freelink\migrators\LinkitMigrator;
use justinholtweb\freelink\migrators\TypedLinkMigrator;
use yii\base\Component;

/**
 * Migration orchestrator service.
 */
class Migrate extends Component
{
    /** @var array<string, class-string<BaseMigrator>> */
    private const MIGRATORS = [
        'hyper' => HyperMigrator::class,
        'linkit' => LinkitMigrator::class,
        'typedlinkfield' => TypedLinkMigrator::class,
        'craftlink' => CraftLinkMigrator::class,
    ];

    /**
     * Returns a migrator instance for the given source plugin.
     */
    public function getMigrator(string $source, ?string $fieldHandle = null, bool $dryRun = false): ?BaseMigrator
    {
        $class = self::MIGRATORS[$source] ?? null;

        if (!$class) {
            return null;
        }

        return new $class($fieldHandle, $dryRun);
    }

    /**
     * Runs a migration from the given source plugin.
     */
    public function runMigration(string $source, ?string $fieldHandle = null, bool $dryRun = false, bool $backup = false): array
    {
        $migrator = $this->getMigrator($source, $fieldHandle, $dryRun);

        if (!$migrator) {
            return [
                'success' => false,
                'log' => ["Unknown source plugin: {$source}"],
            ];
        }

        // Create backup if requested
        if ($backup && !$dryRun) {
            $this->createBackup();
        }

        $success = $migrator->run();

        // Clear caches after migration
        if ($success && !$dryRun) {
            Craft::$app->getFields()->refreshFields();
        }

        return [
            'success' => $success,
            'log' => $migrator->getLog(),
        ];
    }

    /**
     * Returns the status of all migrations.
     */
    public function getStatus(): array
    {
        return (new Query())
            ->select(['fieldId', 'sourcePlugin', 'status', 'log', 'dateCreated', 'dateUpdated'])
            ->from('{{%freelink_migrations}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Returns available migrators with info about whether they have matching fields.
     */
    public function getAvailableMigrations(): array
    {
        $available = [];

        foreach (self::MIGRATORS as $source => $class) {
            $fieldType = $class::sourceFieldType();

            $fieldCount = (new Query())
                ->from('{{%fields}}')
                ->where(['type' => $fieldType])
                ->count();

            if ($fieldCount > 0) {
                $available[$source] = [
                    'source' => $source,
                    'label' => ucfirst($source),
                    'fieldType' => $fieldType,
                    'fieldCount' => (int)$fieldCount,
                ];
            }
        }

        return $available;
    }

    private function createBackup(): void
    {
        Craft::info('Creating database backup before FreeLink migration...', 'freelink');
        Craft::$app->getDb()->backup();
    }
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Services;

use CraftCms\Cms\Database\Backups;
use CraftCms\Cms\Support\Facades\Fields;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Migrators\BaseMigrator;
use JustinHoltWeb\FreeLink\Migrators\CraftLinkMigrator;
use Throwable;

/**
 * Migration orchestrator: other link fields → FreeLink.
 *
 * FreeLink 5 migrated from Hyper, Linkit, Typed Link Field and Craft's Link field. None of the three
 * plugins has a Craft 6 version, so on Craft 6 only Craft's own Link field can be migrated; the
 * other sources are still recognised (the MCP tool and commands keep their names) and say to run
 * the migration on Craft 5 before upgrading.
 */
class Migrate
{
    /** @var array<string, class-string<BaseMigrator>> */
    private const array MIGRATORS = [
        'craftlink' => CraftLinkMigrator::class,
    ];

    /** Sources FreeLink 5 migrated from that can't run on Craft 6, with their labels. */
    public const array CRAFT5_ONLY = [
        'hyper' => 'Hyper',
        'linkit' => 'Linkit',
        'typedlinkfield' => 'Typed Link Field',
    ];

    public const array LABELS = [
        'hyper' => 'Hyper (Verbb)',
        'linkit' => 'Linkit',
        'typedlinkfield' => 'Typed Link Field',
        'craftlink' => 'Craft Link field',
    ];

    public function getMigrator(string $source, ?string $fieldHandle = null, bool $dryRun = false): ?BaseMigrator
    {
        $class = self::MIGRATORS[$source] ?? null;

        return $class ? new $class($fieldHandle, $dryRun) : null;
    }

    /**
     * Runs a migration from the given source.
     *
     * @return array{success: bool, log: list<string>}
     */
    public function runMigration(string $source, ?string $fieldHandle = null, bool $dryRun = false, bool $backup = false): array
    {
        if (isset(self::CRAFT5_ONLY[$source])) {
            return [
                'success' => false,
                'log' => [sprintf(
                    '%s has no Craft 6 version, so its fields can’t be migrated here. Run FreeLink’s %s migration on Craft 5 (FreeLink 5.3) before upgrading to Craft 6.',
                    self::CRAFT5_ONLY[$source],
                    self::CRAFT5_ONLY[$source],
                )],
            ];
        }

        $migrator = $this->getMigrator($source, $fieldHandle, $dryRun);

        if (! $migrator) {
            return ['success' => false, 'log' => ["Unknown source: $source"]];
        }

        $log = [];

        if ($backup && ! $dryRun) {
            try {
                $log[] = 'Database backed up to '.basename(app(Backups::class)->backup()).'.';
            } catch (Throwable $e) {
                return ['success' => false, 'log' => ['The database backup failed, so nothing was migrated: '.$e->getMessage()]];
            }
        }

        $success = $migrator->run();

        return [
            'success' => $success,
            'log' => array_values(array_merge($log, $migrator->getLog())),
        ];
    }

    /**
     * The migration history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getStatus(): array
    {
        return DB::table(Tables::MIGRATIONS)
            ->select(['fieldId', 'sourcePlugin', 'status', 'log', 'dateCreated', 'dateUpdated'])
            ->orderByDesc('dateCreated')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->values()
            ->all();
    }

    /**
     * Sources with fields to migrate.
     *
     * @return array<string, array{source: string, label: string, fieldType: string, fieldCount: int}>
     */
    public function getAvailableMigrations(): array
    {
        $available = [];

        foreach (self::MIGRATORS as $source => $class) {
            $fieldType = $class::sourceFieldType();
            $fieldCount = Fields::getAllFields()->filter(fn ($field) => $field instanceof $fieldType)->count();

            if ($fieldCount > 0) {
                $available[$source] = [
                    'source' => $source,
                    'label' => self::LABELS[$source],
                    'fieldType' => $fieldType,
                    'fieldCount' => $fieldCount,
                ];
            }
        }

        return $available;
    }
}

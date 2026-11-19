<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Migrators;

use CraftCms\Cms\Support\Query;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JustinHoltWeb\FreeLink\Database\Tables;

/**
 * Base class for migrating link fields from another field type to FreeLink.
 */
abstract class BaseMigrator
{
    /** @var string[] */
    protected array $log = [];

    public function __construct(
        protected ?string $fieldHandle = null,
        protected bool $dryRun = false,
    ) {}

    /**
     * The source handle (e.g. `craftlink`).
     */
    abstract public static function sourcePlugin(): string;

    /**
     * The source field type class to detect.
     */
    abstract public static function sourceFieldType(): string;

    /**
     * Runs the migration.
     */
    public function run(): bool
    {
        $this->log('Starting migration from '.static::sourcePlugin());

        $fields = $this->getSourceFields();

        if ($fields === []) {
            $this->log('No matching fields found.');

            return true;
        }

        $this->log('Found '.count($fields).' field(s) to migrate.');

        foreach ($fields as $field) {
            $this->logMigrationStatus((int) $field['id'], 'running');
            $success = $this->migrateField($field);

            if (! $success) {
                $this->logMigrationStatus((int) $field['id'], 'failed');
                $this->log("Failed to migrate field: {$field['handle']}");

                return false;
            }

            $this->logMigrationStatus((int) $field['id'], 'complete');
            $this->log("Successfully migrated field: {$field['handle']}");
        }

        $this->log('Migration complete.');

        return true;
    }

    /**
     * Migrates a single field to FreeLink.
     *
     * @param  array<string, mixed>  $field  `id`, `uid`, `handle`, `name`, `settings` of the source field
     */
    abstract protected function migrateField(array $field): bool;

    /**
     * Maps a source link type to a FreeLink type handle.
     */
    abstract protected function mapType(string $sourceType): ?string;

    /**
     * Returns the source fields to migrate.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function getSourceFields(): array;

    protected function log(string $message): void
    {
        $this->log[] = $message;
        Log::info('[freelink-migration] '.$message);
    }

    /**
     * @return string[]
     */
    public function getLog(): array
    {
        return $this->log;
    }

    private function logMigrationStatus(int $fieldId, string $status): void
    {
        if ($this->dryRun) {
            return;
        }

        $now = Query::prepareDateForDb(now());
        $existing = DB::table(Tables::MIGRATIONS)
            ->where(['fieldId' => $fieldId, 'sourcePlugin' => static::sourcePlugin()])
            ->value('id');

        if ($existing) {
            DB::table(Tables::MIGRATIONS)->where('id', $existing)->update([
                'status' => $status,
                'log' => implode("\n", $this->log),
                'dateUpdated' => $now,
            ]);

            return;
        }

        DB::table(Tables::MIGRATIONS)->insert([
            'fieldId' => $fieldId,
            'sourcePlugin' => static::sourcePlugin(),
            'status' => $status,
            'log' => implode("\n", $this->log),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => (string) Str::uuid(),
        ]);
    }
}

<?php

namespace justinholtweb\freelink\migrators;

use Craft;
use craft\db\Query;
use craft\helpers\Json;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\Plugin;

/**
 * Base class for migrating from other link field plugins to FreeLink.
 */
abstract class BaseMigrator
{
    protected bool $dryRun = false;
    protected array $log = [];
    protected ?string $fieldHandle = null;

    public function __construct(?string $fieldHandle = null, bool $dryRun = false)
    {
        $this->fieldHandle = $fieldHandle;
        $this->dryRun = $dryRun;
    }

    /**
     * The source plugin handle (e.g., 'hyper', 'linkit').
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
        $this->log('Starting migration from ' . static::sourcePlugin());

        $fields = $this->getSourceFields();

        if (empty($fields)) {
            $this->log('No matching fields found.');
            return true;
        }

        $this->log('Found ' . count($fields) . ' field(s) to migrate.');

        foreach ($fields as $field) {
            $this->_logMigrationStatus($field->id, 'running');
            $success = $this->migrateField($field);

            if (!$success) {
                $this->_logMigrationStatus($field->id, 'failed');
                $this->log("Failed to migrate field: {$field->handle}");
                return false;
            }

            $this->_logMigrationStatus($field->id, 'complete');
            $this->log("Successfully migrated field: {$field->handle}");
        }

        $this->log('Migration complete.');
        return true;
    }

    /**
     * Migrates a single field from the source plugin to FreeLink.
     */
    abstract protected function migrateField(object $field): bool;

    /**
     * Maps source link type class/handle to FreeLink handle.
     */
    abstract protected function mapType(string $sourceType): ?string;

    /**
     * Returns source fields to migrate.
     */
    protected function getSourceFields(): array
    {
        $query = (new Query())
            ->select(['id', 'handle', 'name', 'type', 'settings', 'columnSuffix'])
            ->from('{{%fields}}')
            ->where(['type' => static::sourceFieldType()]);

        if ($this->fieldHandle) {
            $query->andWhere(['handle' => $this->fieldHandle]);
        }

        return $query->all();
    }

    /**
     * Updates a field's type to FreeLinkField and saves new settings.
     */
    protected function convertFieldType(int $fieldId, array $newSettings): bool
    {
        if ($this->dryRun) {
            $this->log("[DRY RUN] Would convert field ID {$fieldId} to FreeLinkField");
            return true;
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%fields}}', [
                'type' => FreeLinkField::class,
                'settings' => Json::encode($newSettings),
            ], ['id' => $fieldId])
            ->execute();

        return true;
    }

    /**
     * Creates a relations table row for an element link.
     */
    protected function createRelation(int $fieldId, int $ownerId, int $ownerSiteId, int $sortOrder, int $targetId, ?int $targetSiteId = null): void
    {
        if ($this->dryRun) {
            $this->log("[DRY RUN] Would create relation: field={$fieldId}, owner={$ownerId}, target={$targetId}");
            return;
        }

        Plugin::getInstance()->relations->saveRelations($fieldId, $ownerId, $ownerSiteId, [[
            'sortOrder' => $sortOrder,
            'targetId' => $targetId,
            'targetSiteId' => $targetSiteId,
        ]]);
    }

    /**
     * Updates the content column JSON for a field.
     */
    protected function updateContentJson(string $table, string $column, int $elementId, int $siteId, mixed $json): void
    {
        if ($this->dryRun) {
            $this->log("[DRY RUN] Would update content for element {$elementId}");
            return;
        }

        Craft::$app->getDb()->createCommand()
            ->update($table, [
                $column => is_string($json) ? $json : Json::encode($json),
            ], [
                'elementId' => $elementId,
                'siteId' => $siteId,
            ])
            ->execute();
    }

    protected function log(string $message): void
    {
        $this->log[] = $message;
        Craft::info($message, 'freelink-migration');
    }

    public function getLog(): array
    {
        return $this->log;
    }

    private function _logMigrationStatus(int $fieldId, string $status): void
    {
        if ($this->dryRun) {
            return;
        }

        $db = Craft::$app->getDb();

        $existing = (new Query())
            ->from('{{%freelink_migrations}}')
            ->where([
                'fieldId' => $fieldId,
                'sourcePlugin' => static::sourcePlugin(),
            ])
            ->one();

        if ($existing) {
            $db->createCommand()
                ->update('{{%freelink_migrations}}', [
                    'status' => $status,
                    'log' => implode("\n", $this->log),
                    'dateUpdated' => date('Y-m-d H:i:s'),
                ], ['id' => $existing['id']])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%freelink_migrations}}', [
                    'fieldId' => $fieldId,
                    'sourcePlugin' => static::sourcePlugin(),
                    'status' => $status,
                    'log' => implode("\n", $this->log),
                    'dateCreated' => date('Y-m-d H:i:s'),
                    'dateUpdated' => date('Y-m-d H:i:s'),
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();
        }
    }
}

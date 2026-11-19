<?php

namespace justinholtweb\freelink\mcp;

use craft\mcp\attributes\RequiresAdmin;
use craft\mcp\attributes\RequiresAdminChanges;
use justinholtweb\freelink\Plugin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * FreeLink migration tools for Craft 5.12+'s MCP server. Admin only.
 */
class MigrationTools
{
    public const SOURCES = ['hyper', 'linkit', 'typedlinkfield', 'craftlink'];

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'freelink_migration_status',
        description: 'Lists link fields from other plugins (Hyper, Linkit, Typed Link Field, Craft Link) that can be migrated to FreeLink, and the migration history.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresAdmin]
    public function status(): array
    {
        $migrate = Plugin::getInstance()->migrate;

        return [
            'available' => array_values($migrate->getAvailableMigrations()),
            'history' => array_map(fn(array $row) => [
                'fieldId' => (int)$row['fieldId'],
                'source' => $row['sourcePlugin'],
                'status' => $row['status'],
                'dateCreated' => $row['dateCreated'],
                'dateUpdated' => $row['dateUpdated'],
            ], $migrate->getStatus()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'freelink_migrate',
        description: 'Migrates link fields from another plugin to FreeLink (field type and content). Dry run by default; a real run needs dryRun: false and confirm set to the source name. Back up first.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresAdmin]
    #[RequiresAdminChanges]
    public function migrate(
        #[Schema(enum: self::SOURCES)]
        string $source,
        #[Schema(description: 'One field handle; omit for every field of this type.')]
        ?string $field = null,
        bool $dryRun = true,
        bool $backup = true,
        #[Schema(description: 'Must equal `source` when dryRun is false.')]
        ?string $confirm = null,
    ): array {
        if (!in_array($source, self::SOURCES, true)) {
            throw new ToolCallException('Unknown source.');
        }

        if ($field !== null && $field !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $field)) {
            throw new ToolCallException('Invalid field handle.');
        }

        if (!$dryRun && $confirm !== $source) {
            throw new ToolCallException('A real migration needs confirm set to "' . $source . '".');
        }

        $result = Plugin::getInstance()->migrate->runMigration($source, $field ?: null, $dryRun, $backup);
        $log = array_map('strval', $result['log']);

        if (!$result['success'] && !$dryRun) {
            throw new ToolCallException("Migration failed:\n" . implode("\n", array_slice($log, -10)));
        }

        return [
            'success' => (bool)$result['success'],
            'dryRun' => $dryRun,
            'source' => $source,
            'field' => $field,
            'log' => $log,
        ];
    }
}

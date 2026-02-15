<?php

namespace justinholtweb\freelink\migrators;

use Craft;
use craft\db\Query;
use craft\helpers\Json;

/**
 * Migrates from Sebastian Lenz's Typed Link Field to FreeLink.
 */
class TypedLinkMigrator extends BaseMigrator
{
    private const TYPE_MAP = [
        'url' => 'url',
        'email' => 'email',
        'tel' => 'phone',
        'custom' => 'custom',
        'site' => 'site',
        'entry' => 'entry',
        'asset' => 'asset',
        'category' => 'category',
        'user' => 'user',
    ];

    public static function sourcePlugin(): string
    {
        return 'typedlinkfield';
    }

    public static function sourceFieldType(): string
    {
        return 'lenz\linkfield\fields\LinkField';
    }

    protected function mapType(string $sourceType): ?string
    {
        return self::TYPE_MAP[$sourceType] ?? null;
    }

    protected function migrateField(object $field): bool
    {
        $this->log("Migrating Typed Link field: {$field['handle']} (ID: {$field['id']})");

        $settings = Json::decodeIfJson($field['settings'] ?? '{}');
        $newSettings = $this->convertSettings($settings);

        // Typed Link Field uses its own table instead of the content column
        $this->migrateFromTypedLinkTable((int)$field['id'], $field['handle'], $field['columnSuffix'] ?? null);
        $this->convertFieldType((int)$field['id'], $newSettings);

        return true;
    }

    private function convertSettings(array $tlSettings): array
    {
        $linkTypes = [];
        $sortOrder = 0;

        $allowedTypes = $tlSettings['allowedLinkTypes'] ?? $tlSettings['typeSettings'] ?? [];

        if (is_string($allowedTypes)) {
            $allowedTypes = [$allowedTypes => ['enabled' => true]];
        }

        foreach ($allowedTypes as $typeHandle => $typeConfig) {
            $handle = $this->mapType($typeHandle);

            if (!$handle) {
                continue;
            }

            $enabled = is_array($typeConfig) ? ($typeConfig['enabled'] ?? true) : true;

            $linkTypes[$handle] = [
                'enabled' => $enabled,
                'label' => '',
                'sources' => is_array($typeConfig) ? ($typeConfig['sources'] ?? '*') : '*',
                'sortOrder' => $sortOrder++,
            ];
        }

        if (empty($linkTypes)) {
            $linkTypes['url'] = ['enabled' => true, 'label' => '', 'sources' => '*', 'sortOrder' => 0];
        }

        return [
            'linkTypes' => $linkTypes,
            'multipleLinks' => false,
            'minLinks' => 0,
            'maxLinks' => 0,
            'showLabel' => $tlSettings['allowCustomText'] ?? true,
            'showNewWindow' => $tlSettings['allowTarget'] ?? true,
            'showAdvanced' => true,
            'defaultLinkType' => $tlSettings['defaultLinkType'] ?? 'url',
            'defaultNewWindow' => false,
        ];
    }

    private function migrateFromTypedLinkTable(int $fieldId, string $fieldHandle, ?string $columnSuffix = null): void
    {
        // Check if the lenz_linkfield table exists
        $tableExists = Craft::$app->getDb()->tableExists('{{%lenz_linkfield}}');

        if (!$tableExists) {
            $this->log("  Warning: lenz_linkfield table not found. Skipping content migration.");
            return;
        }

        $rows = (new Query())
            ->select(['elementId', 'siteId', 'type', 'linkedUrl', 'linkedId', 'linkedSiteId', 'payload'])
            ->from('{{%lenz_linkfield}}')
            ->where(['fieldId' => $fieldId])
            ->all();

        $this->log("  Found " . count($rows) . " rows in lenz_linkfield table.");

        $column = $columnSuffix
            ? "field_{$fieldHandle}_{$columnSuffix}"
            : "field_{$fieldHandle}";

        foreach ($rows as $row) {
            $sourceType = $row['type'] ?? '';
            $handle = $this->mapType($sourceType);

            if (!$handle) {
                $this->log("  Skipping unknown type: {$sourceType}");
                continue;
            }

            $payload = Json::decodeIfJson($row['payload'] ?? '{}');

            $newLink = [
                'type' => $handle,
                'value' => $row['linkedUrl'] ?? null,
                'label' => $payload['customText'] ?? null,
                'newWindow' => (bool)($payload['target'] ?? false),
                'ariaLabel' => $payload['ariaLabel'] ?? null,
                'title' => $payload['title'] ?? null,
                'urlSuffix' => null,
                'classes' => null,
                'id' => null,
                'rel' => null,
                'customAttributes' => [],
            ];

            // Element link handling
            if (!empty($row['linkedId'])) {
                $targetId = (int)$row['linkedId'];
                $targetSiteId = !empty($row['linkedSiteId']) ? (int)$row['linkedSiteId'] : null;

                if (!$this->dryRun) {
                    \justinholtweb\freelink\Plugin::getInstance()->relations->saveRelations(
                        $fieldId,
                        (int)$row['elementId'],
                        (int)$row['siteId'],
                        [[
                            'sortOrder' => 0,
                            'targetId' => $targetId,
                            'targetSiteId' => $targetSiteId,
                        ]],
                    );
                }

                $newLink['value'] = null;
            }

            // Write the data to the content column (Typed Link Field uses its own table)
            $this->updateContentJson(
                '{{%content}}',
                $column,
                (int)$row['elementId'],
                (int)$row['siteId'],
                $newLink,
            );
        }
    }
}

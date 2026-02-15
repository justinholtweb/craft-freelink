<?php

namespace justinholtweb\freelink\migrators;

use Craft;
use craft\db\Query;
use craft\helpers\Json;

/**
 * Migrates from Craft CMS native Link field to FreeLink.
 */
class CraftLinkMigrator extends BaseMigrator
{
    private const TYPE_MAP = [
        'url' => 'url',
        'email' => 'email',
        'phone' => 'phone',
        'entry' => 'entry',
        'asset' => 'asset',
        'category' => 'category',
        'user' => 'user',
        'custom' => 'custom',
    ];

    public static function sourcePlugin(): string
    {
        return 'craftlink';
    }

    public static function sourceFieldType(): string
    {
        return 'craft\fields\Link';
    }

    protected function mapType(string $sourceType): ?string
    {
        return self::TYPE_MAP[$sourceType] ?? null;
    }

    protected function migrateField(object $field): bool
    {
        $this->log("Migrating Craft Link field: {$field['handle']} (ID: {$field['id']})");

        $settings = Json::decodeIfJson($field['settings'] ?? '{}');
        $newSettings = $this->convertSettings($settings);

        $this->migrateFieldContent((int)$field['id'], $field['handle'], $field['columnSuffix'] ?? null);
        $this->convertFieldType((int)$field['id'], $newSettings);

        return true;
    }

    private function convertSettings(array $craftSettings): array
    {
        $linkTypes = [];
        $sortOrder = 0;

        $allowedTypes = $craftSettings['types'] ?? ['url'];

        foreach ($allowedTypes as $typeHandle) {
            $handle = $this->mapType($typeHandle);

            if (!$handle) {
                continue;
            }

            $linkTypes[$handle] = [
                'enabled' => true,
                'label' => '',
                'sources' => $craftSettings['sources'][$typeHandle] ?? '*',
                'sortOrder' => $sortOrder++,
            ];
        }

        if (empty($linkTypes)) {
            $linkTypes['url'] = ['enabled' => true, 'label' => '', 'sources' => '*', 'sortOrder' => 0];
        }

        $showAdvanced = !empty($craftSettings['showClassField'])
            || !empty($craftSettings['showIdField'])
            || !empty($craftSettings['showRelField']);

        return [
            'linkTypes' => $linkTypes,
            'multipleLinks' => false,
            'minLinks' => 0,
            'maxLinks' => 0,
            'showLabel' => $craftSettings['showLabelField'] ?? true,
            'showNewWindow' => $craftSettings['showTargetField'] ?? true,
            'showAdvanced' => $showAdvanced,
            'defaultLinkType' => array_key_first($linkTypes) ?? 'url',
            'defaultNewWindow' => false,
        ];
    }

    private function migrateFieldContent(int $fieldId, string $fieldHandle, ?string $columnSuffix = null): void
    {
        $column = $columnSuffix
            ? "field_{$fieldHandle}_{$columnSuffix}"
            : "field_{$fieldHandle}";

        $rows = (new Query())
            ->select(['elementId', 'siteId', $column])
            ->from('{{%content}}')
            ->where(['not', [$column => null]])
            ->andWhere(['not', [$column => '']])
            ->all();

        $this->log("  Found " . count($rows) . " content rows to migrate.");

        foreach ($rows as $row) {
            $data = Json::decodeIfJson($row[$column]);

            if (!is_array($data)) {
                continue;
            }

            $sourceType = $data['type'] ?? '';
            $handle = $this->mapType($sourceType);

            if (!$handle) {
                // Fallback: treat unknown types as url
                $handle = 'url';
            }

            $newLink = [
                'type' => $handle,
                'value' => $data['value'] ?? $data['url'] ?? null,
                'label' => $data['label'] ?? $data['text'] ?? null,
                'newWindow' => (bool)($data['target'] ?? false),
                'ariaLabel' => $data['ariaLabel'] ?? null,
                'title' => $data['title'] ?? null,
                'urlSuffix' => $data['suffix'] ?? null,
                'classes' => $data['class'] ?? null,
                'id' => $data['id'] ?? null,
                'rel' => $data['rel'] ?? null,
                'customAttributes' => [],
            ];

            // Element link handling
            $elementTypes = ['entry', 'asset', 'category', 'user'];
            if (in_array($handle, $elementTypes)) {
                // Craft's native link field stores element ID in value or via relations
                $targetId = !empty($data['value']) ? (int)$data['value'] : null;

                if ($targetId) {
                    if (!$this->dryRun) {
                        \justinholtweb\freelink\Plugin::getInstance()->relations->saveRelations(
                            $fieldId,
                            (int)$row['elementId'],
                            (int)$row['siteId'],
                            [[
                                'sortOrder' => 0,
                                'targetId' => $targetId,
                                'targetSiteId' => null,
                            ]],
                        );
                    }

                    $newLink['value'] = null;
                }
            }

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

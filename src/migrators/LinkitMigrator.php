<?php

namespace justinholtweb\freelink\migrators;

use Craft;
use craft\db\Query;
use craft\helpers\Json;

/**
 * Migrates from Pressed Digital's Linkit to FreeLink.
 */
class LinkitMigrator extends BaseMigrator
{
    private const TYPE_MAP = [
        'presseddigital\linkit\models\Url' => 'url',
        'presseddigital\linkit\models\Email' => 'email',
        'presseddigital\linkit\models\Phone' => 'phone',
        'presseddigital\linkit\models\Custom' => 'custom',
        'presseddigital\linkit\models\Entry' => 'entry',
        'presseddigital\linkit\models\Asset' => 'asset',
        'presseddigital\linkit\models\Category' => 'category',
        'presseddigital\linkit\models\User' => 'user',
        'presseddigital\linkit\models\Product' => 'product',
        // Social types map to URL
        'presseddigital\linkit\models\Twitter' => 'url',
        'presseddigital\linkit\models\Facebook' => 'url',
        'presseddigital\linkit\models\Instagram' => 'url',
        'presseddigital\linkit\models\LinkedIn' => 'url',
    ];

    public static function sourcePlugin(): string
    {
        return 'linkit';
    }

    public static function sourceFieldType(): string
    {
        return 'presseddigital\linkit\fields\LinkitField';
    }

    protected function mapType(string $sourceType): ?string
    {
        return self::TYPE_MAP[$sourceType] ?? null;
    }

    protected function migrateField(object $field): bool
    {
        $this->log("Migrating Linkit field: {$field['handle']} (ID: {$field['id']})");

        $settings = Json::decodeIfJson($field['settings'] ?? '{}');
        $newSettings = $this->convertSettings($settings);

        $this->migrateFieldContent((int)$field['id'], $field['handle'], $field['columnSuffix'] ?? null);
        $this->convertFieldType((int)$field['id'], $newSettings);

        return true;
    }

    private function convertSettings(array $linkitSettings): array
    {
        $linkTypes = [];
        $sortOrder = 0;

        $enabledTypes = $linkitSettings['types'] ?? [];

        foreach ($enabledTypes as $typeClass => $typeConfig) {
            $handle = $this->mapType($typeClass);

            if (!$handle) {
                $this->log("  Skipping unknown Linkit type: {$typeClass}");
                continue;
            }

            // Avoid duplicate handles (multiple social types map to 'url')
            if (isset($linkTypes[$handle])) {
                continue;
            }

            $linkTypes[$handle] = [
                'enabled' => true,
                'label' => $typeConfig['customLabel'] ?? '',
                'sources' => $typeConfig['sources'] ?? '*',
                'sortOrder' => $sortOrder++,
            ];
        }

        // Ensure at least url type is enabled
        if (empty($linkTypes)) {
            $linkTypes['url'] = ['enabled' => true, 'label' => '', 'sources' => '*', 'sortOrder' => 0];
        }

        return [
            'linkTypes' => $linkTypes,
            'multipleLinks' => false,
            'minLinks' => 0,
            'maxLinks' => 0,
            'showLabel' => $linkitSettings['allowCustomText'] ?? true,
            'showNewWindow' => $linkitSettings['allowTarget'] ?? true,
            'showAdvanced' => false,
            'defaultLinkType' => $linkitSettings['defaultText'] ?? 'url',
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
                $this->log("  Skipping unknown type: {$sourceType}");
                continue;
            }

            $newLink = [
                'type' => $handle,
                'value' => $data['value'] ?? null,
                'label' => $data['customText'] ?? null,
                'newWindow' => (bool)($data['target'] ?? false),
                'ariaLabel' => null,
                'title' => null,
                'urlSuffix' => null,
                'classes' => null,
                'id' => null,
                'rel' => null,
                'customAttributes' => [],
            ];

            // Element link handling
            $elementTypes = ['entry', 'asset', 'category', 'user', 'product'];
            if (in_array($handle, $elementTypes) && !empty($data['value'])) {
                $targetId = (int)$data['value'];

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

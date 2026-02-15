<?php

namespace justinholtweb\freelink\migrators;

use Craft;
use craft\db\Query;
use craft\helpers\Json;

/**
 * Migrates from Verbb Hyper to FreeLink.
 */
class HyperMigrator extends BaseMigrator
{
    private const TYPE_MAP = [
        'verbb\hyper\links\Url' => 'url',
        'verbb\hyper\links\Email' => 'email',
        'verbb\hyper\links\Phone' => 'phone',
        'verbb\hyper\links\Custom' => 'custom',
        'verbb\hyper\links\Site' => 'site',
        'verbb\hyper\links\Entry' => 'entry',
        'verbb\hyper\links\Asset' => 'asset',
        'verbb\hyper\links\Category' => 'category',
        'verbb\hyper\links\User' => 'user',
        'verbb\hyper\links\Product' => 'product',
        'verbb\hyper\links\Variant' => 'variant',
    ];

    public static function sourcePlugin(): string
    {
        return 'hyper';
    }

    public static function sourceFieldType(): string
    {
        return 'verbb\hyper\fields\HyperField';
    }

    protected function mapType(string $sourceType): ?string
    {
        return self::TYPE_MAP[$sourceType] ?? null;
    }

    protected function migrateField(object $field): bool
    {
        $this->log("Migrating Hyper field: {$field['handle']} (ID: {$field['id']})");

        $settings = Json::decodeIfJson($field['settings'] ?? '{}');

        // Convert Hyper field settings to FreeLink settings
        $newSettings = $this->convertSettings($settings);

        // Migrate content data
        $this->migrateFieldContent((int)$field['id'], $field['handle'], $field['columnSuffix'] ?? null);

        // Convert the field type
        $this->convertFieldType((int)$field['id'], $newSettings);

        return true;
    }

    private function convertSettings(array $hyperSettings): array
    {
        $linkTypes = [];
        $sortOrder = 0;

        $hyperLinkTypes = $hyperSettings['linkTypes'] ?? [];

        foreach ($hyperLinkTypes as $typeConfig) {
            $typeClass = $typeConfig['type'] ?? '';
            $handle = $this->mapType($typeClass);

            if (!$handle) {
                $this->log("  Skipping unknown Hyper type: {$typeClass}");
                continue;
            }

            $linkTypes[$handle] = [
                'enabled' => $typeConfig['enabled'] ?? true,
                'label' => $typeConfig['label'] ?? '',
                'sources' => $typeConfig['sources'] ?? '*',
                'sortOrder' => $sortOrder++,
            ];
        }

        return [
            'linkTypes' => $linkTypes,
            'multipleLinks' => $hyperSettings['multipleLinks'] ?? false,
            'minLinks' => $hyperSettings['minLinks'] ?? 0,
            'maxLinks' => $hyperSettings['maxLinks'] ?? 0,
            'showLabel' => true,
            'showNewWindow' => $hyperSettings['newWindow'] ?? true,
            'showAdvanced' => true,
            'defaultLinkType' => $hyperSettings['defaultLinkType'] ?? 'url',
            'defaultNewWindow' => $hyperSettings['defaultNewWindow'] ?? false,
        ];
    }

    private function migrateFieldContent(int $fieldId, string $fieldHandle, ?string $columnSuffix = null): void
    {
        // Determine the content column name
        $column = $this->getContentColumnName($fieldHandle, $columnSuffix);

        // Find all content rows with data in this field
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

            // Hyper stores as array of links
            $links = isset($data['type']) ? [$data] : $data;

            $newLinks = [];
            $relations = [];

            foreach ($links as $sortOrder => $hyperLink) {
                $typeClass = $hyperLink['type'] ?? '';
                $handle = $this->mapType($typeClass);

                if (!$handle) {
                    $this->log("  Skipping unknown link type: {$typeClass}");
                    continue;
                }

                $newLink = [
                    'type' => $handle,
                    'value' => $hyperLink['linkValue'] ?? null,
                    'label' => $hyperLink['linkText'] ?? null,
                    'newWindow' => (bool)($hyperLink['newWindow'] ?? false),
                    'ariaLabel' => $hyperLink['ariaLabel'] ?? null,
                    'title' => $hyperLink['title'] ?? null,
                    'urlSuffix' => $hyperLink['urlSuffix'] ?? null,
                    'classes' => $hyperLink['classes'] ?? null,
                    'id' => $hyperLink['linkId'] ?? null,
                    'rel' => null,
                    'customAttributes' => $hyperLink['customAttributes'] ?? [],
                ];

                // Check if this is an element link
                $elementTypes = ['entry', 'asset', 'category', 'user', 'product', 'variant'];
                if (in_array($handle, $elementTypes) && !empty($hyperLink['linkValue'])) {
                    $targetId = (int)$hyperLink['linkValue'];
                    $targetSiteId = !empty($hyperLink['linkSiteId']) ? (int)$hyperLink['linkSiteId'] : null;

                    $relations[] = [
                        'sortOrder' => $sortOrder,
                        'targetId' => $targetId,
                        'targetSiteId' => $targetSiteId,
                    ];

                    // Element links store null value in JSON
                    $newLink['value'] = null;
                }

                $newLinks[] = $newLink;
            }

            // Update the content JSON
            $jsonValue = count($newLinks) === 1 ? $newLinks[0] : $newLinks;
            $this->updateContentJson(
                '{{%content}}',
                $column,
                (int)$row['elementId'],
                (int)$row['siteId'],
                $jsonValue,
            );

            // Create relations table rows for element links
            if (!empty($relations) && !$this->dryRun) {
                \justinholtweb\freelink\Plugin::getInstance()->relations->saveRelations(
                    $fieldId,
                    (int)$row['elementId'],
                    (int)$row['siteId'],
                    $relations,
                );
            }
        }
    }

    private function getContentColumnName(string $fieldHandle, ?string $columnSuffix): string
    {
        if ($columnSuffix) {
            return "field_{$fieldHandle}_{$columnSuffix}";
        }

        return "field_{$fieldHandle}";
    }
}

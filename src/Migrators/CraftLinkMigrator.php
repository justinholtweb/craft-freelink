<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Migrators;

use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Field\Link as CraftLinkField;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Json;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Fields\LinkControl;
use JustinHoltWeb\FreeLink\Links\Asset;
use JustinHoltWeb\FreeLink\Links\Entry;
use JustinHoltWeb\FreeLink\Links\Url;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Relations;
use Throwable;

/**
 * Migrates Craft's own Link field to FreeLink, on Craft 6.
 *
 * Craft 6 keeps field values in `elements_sites.content`, a JSON object keyed by each field layout
 * element's UID. A Link value there is `{value, type, label?, target?, class?, …}` (element values
 * are `{entry:12@1:url}` reference tags, email/phone values carry `mailto:`/`tel:`), or, for links
 * saved with nothing but a URL, a bare string. Each one is rewritten as FreeLink JSON in place, under
 * the same layout element UID; element targets go into `freelink_links`. Drafts and revisions are
 * converted too, so they still load. The field itself is then saved as a FreeLink field with the
 * same ID, UID, name and handle.
 */
class CraftLinkMigrator extends BaseMigrator
{
    /** Craft link type → FreeLink type. Craft 6 ships these six. */
    private const array TYPE_MAP = [
        'url' => 'url',
        'email' => 'email',
        'phone' => 'phone',
        'sms' => 'sms',
        'entry' => 'entry',
        'asset' => 'asset',
    ];

    private const array PREFIXES = [
        'email' => 'mailto:',
        'phone' => 'tel:',
        'sms' => 'sms:',
    ];

    public static function sourcePlugin(): string
    {
        return 'craftlink';
    }

    public static function sourceFieldType(): string
    {
        return CraftLinkField::class;
    }

    protected function mapType(string $sourceType): ?string
    {
        return self::TYPE_MAP[$sourceType] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getSourceFields(): array
    {
        $fields = [];

        foreach (Fields::getAllFields() as $field) {
            if (! $field instanceof CraftLinkField || ! $field->id) {
                continue;
            }

            if ($this->fieldHandle && $field->handle !== $this->fieldHandle) {
                continue;
            }

            $fields[] = [
                'id' => (int) $field->id,
                'uid' => $field->uid,
                'handle' => (string) $field->handle,
                'name' => (string) $field->name,
                'settings' => $field->getSettings(),
            ];
        }

        return $fields;
    }

    protected function migrateField(array $field): bool
    {
        $fieldId = (int) $field['id'];
        $this->log("Migrating Craft Link field: {$field['handle']} (ID: $fieldId)");

        $source = Fields::getFieldById($fieldId);

        if (! $source instanceof CraftLinkField) {
            $this->log('  The field is no longer a Link field.');

            return false;
        }

        /** @var array<string, mixed> $settings */
        $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];
        $newSettings = $this->convertSettings($settings);
        $layoutElements = $this->layoutElementUids($fieldId);

        if ($layoutElements === []) {
            $this->log('  The field isn’t in any field layout; only its type changes.');
        }

        try {
            $converted = 0;
            $relations = 0;
            $owners = [];

            DB::transaction(function () use ($fieldId, $layoutElements, $newSettings, &$converted, &$relations, &$owners): void {
                foreach ($layoutElements as $layoutId => $uids) {
                    $rows = DB::table(Table::ELEMENTS_SITES.' as es')
                        ->join(Table::ELEMENTS.' as e', 'e.id', '=', 'es.elementId')
                        ->where('e.fieldLayoutId', $layoutId)
                        ->whereNotNull('es.content')
                        ->select(['es.id', 'es.elementId', 'es.siteId', 'es.content', 'e.type', 'e.draftId', 'e.revisionId'])
                        ->orderBy('es.id')
                        ->get();

                    foreach ($rows as $row) {
                        $content = Json::decodeIfJson((string) $row->content);

                        if (! is_array($content)) {
                            continue;
                        }

                        $changed = false;

                        foreach ($uids as $uid) {
                            if (! array_key_exists($uid, $content) || $content[$uid] === null || $content[$uid] === '') {
                                continue;
                            }

                            [$link, $targetId, $targetSiteId] = $this->convertValue($content[$uid], $newSettings);

                            if ($link === null) {
                                $this->log("  Element {$row->elementId} (site {$row->siteId}): couldn’t read the stored link; left as it was.");

                                continue;
                            }

                            $content[$uid] = $link;
                            $changed = true;
                            $converted++;

                            if ($targetId !== null) {
                                $relations++;

                                if (! $this->dryRun) {
                                    app(Relations::class)->saveRelations($fieldId, (int) $row->elementId, (int) $row->siteId, [[
                                        'sortOrder' => 0,
                                        'targetId' => $targetId,
                                        'targetSiteId' => $targetSiteId,
                                    ]]);
                                }
                            }
                        }

                        if ($changed && ! $this->dryRun) {
                            DB::table(Table::ELEMENTS_SITES)->where('id', $row->id)->update([
                                'content' => Json::encode($content),
                            ]);
                        }

                        if ($changed && $row->draftId === null && $row->revisionId === null) {
                            foreach ($uids as $uid) {
                                if (is_array($content[$uid] ?? null)) {
                                    $owners[] = [(int) $row->elementId, (string) $row->type, (int) $row->siteId, $content[$uid]];
                                }
                            }
                        }
                    }
                }

                if ($this->dryRun) {
                    return;
                }

                $this->convertFieldType($fieldId, $newSettings);
            });

            $prefix = $this->dryRun ? '[DRY RUN] Would convert' : 'Converted';
            $this->log("  $prefix $converted value(s), $relations of them element links.");
        } catch (Throwable $e) {
            $this->log('  Failed: '.$e->getMessage());

            return false;
        }

        if ($this->dryRun) {
            $this->log("[DRY RUN] Would convert field ID $fieldId to FreeLinkField");

            return true;
        }

        // The link index follows the new field values (live content only). Field layouts loaded
        // earlier in this request still hold the old field, so the new field indexes the values.
        Fields::refreshFields();
        $field = Fields::getFieldById($fieldId);
        $index = app(LinkIndex::class);

        foreach ($owners as [$elementId, $type, $siteId, $json]) {
            try {
                $element = class_exists($type) ? $type::find()->id($elementId)->siteId($siteId)->status(null)->one() : null;

                if ($field instanceof FreeLinkField && $element) {
                    $index->indexValue($field, $element, $field->normalizeValue($json, $element));
                }
            } catch (Throwable $e) {
                $this->log("  Couldn’t index element $elementId: {$e->getMessage()}");
            }
        }

        return true;
    }

    /**
     * Layout element UIDs of the field, by field layout ID. A field can be in several layouts, and
     * each instance has its own UID (and so its own key in the content JSON).
     *
     * @return array<int, list<string>>
     */
    private function layoutElementUids(int $fieldId): array
    {
        $uids = [];

        foreach (Fields::getAllLayouts() as $layout) {
            if (! $layout->id) {
                continue;
            }

            foreach ($layout->getCustomFieldElements() as $layoutElement) {
                try {
                    $field = $layoutElement->getField();
                } catch (Throwable) {
                    continue;
                }

                if ((int) $field->id === $fieldId && $layoutElement->uid) {
                    $uids[(int) $layout->id][] = (string) $layoutElement->uid;
                }
            }
        }

        return $uids;
    }

    /**
     * @param  array<string, mixed>  $newSettings
     */
    private function convertFieldType(int $fieldId, array $newSettings): void
    {
        $source = Fields::getFieldById($fieldId);

        if (! $source) {
            throw new \RuntimeException("Field $fieldId is gone.");
        }

        $field = Fields::createField([
            'type' => FreeLinkField::class,
            'id' => $source->id,
            'uid' => $source->uid,
            'name' => $source->name,
            'handle' => $source->handle,
            'context' => $source->context,
            'instructions' => $source->instructions,
            'searchable' => $source->searchable,
            'translationMethod' => $source->translationMethod,
            'translationKeyFormat' => $source->translationKeyFormat,
            'settings' => $newSettings,
        ]);

        if (! Fields::saveField($field)) {
            throw new \RuntimeException('Craft didn’t save the field as a FreeLink field.');
        }
    }

    /**
     * FreeLink settings equivalent to a Craft Link field's.
     *
     * @param  array<string, mixed>  $craftSettings
     * @return array<string, mixed>
     */
    public function convertSettings(array $craftSettings): array
    {
        $linkTypes = [];
        $sortOrder = 0;
        $typeSettings = is_array($craftSettings['typeSettings'] ?? null) ? $craftSettings['typeSettings'] : [];

        foreach ((array) ($craftSettings['types'] ?? ['entry', 'url']) as $typeHandle) {
            $handle = is_string($typeHandle) ? $this->mapType($typeHandle) : null;

            if (! $handle) {
                $this->log('  Link type “'.(is_string($typeHandle) ? $typeHandle : '?').'” has no FreeLink equivalent; its links become URL or custom links.');

                continue;
            }

            $type = ['enabled' => true, 'label' => '', 'sortOrder' => $sortOrder++];

            if (in_array($handle, ['entry', 'asset'], true)) {
                $settings = is_array($typeSettings[$typeHandle] ?? null) ? $typeSettings[$typeHandle] : [];
                $type['sources'] = FreeLinkField::normalizeSources($settings['sources'] ?? '*');

                if (is_array($settings['selectionCondition'] ?? null) && ! empty($settings['selectionCondition']['conditionRules'])) {
                    $type['selectionCondition'] = $settings['selectionCondition'];
                }
            }

            $linkTypes[$handle] = $type;
        }

        // Root-relative URLs, anchors and custom schemes are valid Craft URL links but not FreeLink
        // URL links (which need a host), so they become custom links, and the field allows those.
        $url = is_array($typeSettings['url'] ?? null) ? $typeSettings['url'] : [];
        if (isset($linkTypes['url']) && (! empty($url['allowRootRelativeUrls']) || ! empty($url['allowAnchors']) || ! empty($url['allowCustomSchemes']))) {
            $linkTypes['custom'] = ['enabled' => true, 'label' => '', 'sortOrder' => $sortOrder++];
        }

        if ($linkTypes === []) {
            $linkTypes['url'] = ['enabled' => true, 'label' => '', 'sortOrder' => 0];
        }

        $advanced = (array) ($craftSettings['advancedFields'] ?? []);

        if (in_array('download', $advanced, true)) {
            $this->log('  FreeLink has no “download” attribute; download/filename settings are dropped.');
        }

        return [
            'linkTypes' => $linkTypes,
            'multipleLinks' => false,
            'minLinks' => 0,
            'maxLinks' => 0,
            'showLabel' => (bool) ($craftSettings['showLabelField'] ?? false),
            'showNewWindow' => in_array('target', $advanced, true),
            'showAdvanced' => array_intersect(['urlSuffix', 'title', 'class', 'id', 'rel', 'ariaLabel'], $advanced) !== [],
            'defaultLinkType' => (string) array_key_first($linkTypes),
            'defaultNewWindow' => false,
            'allowIcons' => false,
            'includeProIcons' => false,
        ];
    }

    /**
     * A stored Link value as FreeLink JSON, plus its element target.
     *
     * @param  array<string, mixed>  $settings  The new FreeLink settings
     * @return array{0: array<string, mixed>|null, 1: int|null, 2: int|null}
     */
    public function convertValue(mixed $stored, array $settings): array
    {
        if (is_string($stored)) {
            $decoded = Json::decodeIfJson($stored);
            $stored = is_array($decoded) ? $decoded : ['value' => $stored, 'type' => null];
        }

        if (! is_array($stored)) {
            return [null, null, null];
        }

        $raw = is_scalar($stored['value'] ?? null) ? trim((string) $stored['value']) : '';
        $sourceType = is_string($stored['type'] ?? null) ? $stored['type'] : self::guessType($raw);
        $type = $this->mapType($sourceType);
        $targetId = null;
        $targetSiteId = null;
        $value = $raw;
        $subject = null;

        if ($type === 'entry' || $type === 'asset') {
            [$targetId, $targetSiteId] = LinkControl::parseReference($raw, $type === 'entry' ? Entry::class : Asset::class);
            $value = null;
        } elseif ($type !== null && isset(self::PREFIXES[$type])) {
            $value = stripos($raw, self::PREFIXES[$type]) === 0 ? substr($raw, strlen(self::PREFIXES[$type])) : $raw;

            if ($type === 'email' && preg_match('/^([^?]*)\?(.*)$/s', $value, $m)) {
                $value = rawurldecode($m[1]);
                parse_str($m[2], $query);
                $subject = is_string($query['subject'] ?? null) ? $query['subject'] : null;
            }
        } elseif ($type === null || ($type === 'url' && ! preg_match(Url::PATTERN, $raw))) {
            // Root-relative URLs, anchors, custom schemes and unknown types.
            $type = isset($settings['linkTypes']['custom']) ? 'custom' : 'url';
        }

        $link = [
            'type' => $type,
            'value' => $value,
            'label' => self::str($stored['label'] ?? null),
            'newWindow' => ($stored['target'] ?? null) === '_blank',
            'ariaLabel' => self::str($stored['ariaLabel'] ?? null),
            'title' => self::str($stored['title'] ?? null),
            'urlSuffix' => self::str($stored['urlSuffix'] ?? null),
            'classes' => self::str($stored['class'] ?? null),
            'id' => self::str($stored['id'] ?? null),
            'rel' => self::str($stored['rel'] ?? null),
            'customAttributes' => [],
        ];

        if ($type === 'email') {
            $link['subject'] = $subject;
        }

        return [$link, $targetId, $targetSiteId];
    }

    private static function guessType(string $value): string
    {
        return match (true) {
            str_starts_with($value, 'mailto:') => 'email',
            str_starts_with($value, 'tel:') => 'phone',
            str_starts_with($value, 'sms:') => 'sms',
            (bool) preg_match('/^\{entry:\d+/', $value) => 'entry',
            (bool) preg_match('/^\{asset:\d+/', $value) => 'asset',
            default => 'url',
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}

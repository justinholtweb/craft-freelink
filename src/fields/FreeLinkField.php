<?php

namespace justinholtweb\freelink\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\InputSchemaFieldInterface;
use craft\base\McpOutputFieldInterface;
use craft\elements\conditions\ElementCondition;
use craft\elements\conditions\ElementConditionInterface;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\gql\GqlScope;
use justinholtweb\freelink\gql\types\input\LinkInput;
use justinholtweb\freelink\helpers\Icons;
use justinholtweb\freelink\helpers\TargetVisibility;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\Plugin;

// Craft 5.12+'s MCP field interfaces, declared here when the installed Craft predates them.
require_once dirname(__DIR__, 2) . '/polyfills/craft-mcp.php';

class FreeLinkField extends Field implements InputSchemaFieldInterface, McpOutputFieldInterface
{
    // region Settings

    /**
     * Configured link types. Array of type handle => type config:
     * ['enabled' => bool, 'label' => string, 'sources' => string|array, 'sortOrder' => int,
     *  'selectionCondition' => array (element types only, an element condition config)]
     *
     * @var array<string, array<string, mixed>>
     */
    public array $linkTypes = [];

    public bool $multipleLinks = false;
    public int $minLinks = 0;
    public int $maxLinks = 0;
    public bool $showLabel = true;
    public bool $showNewWindow = true;
    public bool $showAdvanced = false;
    public string $defaultLinkType = 'url';
    public bool $defaultNewWindow = false;

    /** Let editors pick an icon per link (5.3). */
    public bool $allowIcons = false;

    /**
     * Offer Font Awesome Pro icons too. Off by default: Craft's Pro icons are licensed for the CP,
     * and per-link icons render on the front end.
     */
    public bool $includeProIcons = false;

    /**
     * Selection conditions built from `linkTypes[*][selectionCondition]`, keyed by type handle + config.
     * Built lazily: creating a condition can load every field, and a field rule in one of these
     * conditions would otherwise recurse back here while the fields are being loaded.
     *
     * @var array<string, ElementConditionInterface|null>
     */
    private array $_selectionConditions = [];

    // endregion

    public static function displayName(): string
    {
        return 'FreeLink';
    }

    public static function icon(): string
    {
        return 'link';
    }

    /**
     * @return array<string, mixed>|string
     */
    public function getContentColumnType(): array|string
    {
        return 'text';
    }

    // region Settings

    public function getSettingsHtml(): ?string
    {
        $linksService = Plugin::getInstance()->links;
        $availableTypes = $linksService->getAvailableTypes();

        $typeOptions = [];
        foreach ($availableTypes as $handle => $class) {
            $isElement = is_subclass_of($class, ElementLink::class);
            $sources = $this->linkTypes[$handle]['sources'] ?? '*';

            $typeOptions[] = [
                'handle' => $handle,
                'label' => $class::displayName(),
                'isElement' => $isElement,
                'enabled' => $this->linkTypes[$handle]['enabled'] ?? ($handle === 'url'),
                'customLabel' => $this->linkTypes[$handle]['label'] ?? '',
                'sources' => $this->normalizeSources($sources),
                'selectionConditionHtml' => $isElement ? $this->selectionConditionBuilderHtml($handle) : null,
            ];
        }

        return Craft::$app->getView()->renderTemplate('freelink/field/settings', [
            'field' => $this,
            'typeOptions' => $typeOptions,
        ]);
    }

    /**
     * Field settings as they go to project config. Selection conditions are stored as their config
     * (what the condition builder posts is a builder payload, not a config), and dropped when they
     * have no rules.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $linkTypes = [];

        foreach ($this->linkTypes as $handle => $config) {
            if (!is_array($config)) {
                continue;
            }

            if (isset($config['sources'])) {
                $config['sources'] = $this->normalizeSources($config['sources']);
            }

            unset($config['selectionCondition']);
            $condition = $this->getSelectionCondition((string)$handle);
            if ($condition) {
                $config['selectionCondition'] = $condition->getConfig();
            }

            $linkTypes[$handle] = $config;
        }

        $settings['linkTypes'] = $linkTypes;

        return $settings;
    }

    /**
     * Returns the condition an element link type's selected element has to match, or null when the
     * type has none (or isn't an element link type).
     *
     * The condition narrows the element select modal and is enforced again when the owner is saved.
     */
    public function getSelectionCondition(string $handle): ?ElementConditionInterface
    {
        $config = $this->linkTypes[$handle]['selectionCondition'] ?? null;

        // Keyed on the config too, so a changed setting is never answered from the old condition.
        $key = $handle . ':' . ($config instanceof ElementConditionInterface ? spl_object_id($config) : md5((string)json_encode($config)));
        if (array_key_exists($key, $this->_selectionConditions)) {
            return $this->_selectionConditions[$key];
        }

        $condition = null;
        $elementType = $this->elementTypeFor($handle);

        if ($elementType && $config) {
            try {
                if ($config instanceof ElementConditionInterface) {
                    $created = $config;
                } else {
                    /** @var array{class: class-string<ElementConditionInterface>}|class-string<ElementConditionInterface> $config */
                    $created = Craft::$app->getConditions()->createCondition($config);
                }

                // Only a condition for this type's own element type counts. Anything else is a
                // stale or hand-edited config, and applying it would filter on the wrong rules.
                if (
                    $created instanceof ElementConditionInterface &&
                    (!$created instanceof ElementCondition || $created->elementType === null || $created->elementType === $elementType) &&
                    !empty($created->getConditionRules())
                ) {
                    $condition = $created;
                }
            } catch (\Throwable $e) {
                Craft::warning("FreeLink field \"$this->handle\": couldn't build the selection condition for the \"$handle\" link type: {$e->getMessage()}", __METHOD__);
            }
        }

        return $this->_selectionConditions[$key] = $condition;
    }

    /**
     * The element class an element link type targets, or null for a simple link type.
     *
     * @return class-string<ElementInterface>|null
     */
    private function elementTypeFor(string $handle): ?string
    {
        $class = Plugin::getInstance()->links->getTypeByHandle($handle);

        if (!$class || !is_subclass_of($class, ElementLink::class)) {
            return null;
        }

        $elementType = $class::elementType();

        return class_exists($elementType) && is_subclass_of($elementType, ElementInterface::class) ? $elementType : null;
    }

    /**
     * The condition builder for an element link type's selection condition, wrapped as a field.
     */
    private function selectionConditionBuilderHtml(string $handle): ?string
    {
        $elementType = $this->elementTypeFor($handle);

        if (!$elementType) {
            return null;
        }

        $condition = $this->getSelectionCondition($handle) ?? $elementType::createCondition();
        $condition->mainTag = 'div';
        $condition->id = 'freelink-selection-condition-' . $handle;
        $condition->name = 'linkTypes[' . $handle . '][selectionCondition]';
        $condition->forProjectConfig = true;
        $condition->queryParams[] = 'site';

        return Cp::fieldHtml($condition->getBuilderHtml(), [
            'label' => Craft::t('freelink', 'Selectable {type} Condition', [
                'type' => $elementType::pluralDisplayName(),
            ]),
            'instructions' => StringHelper::upperCaseFirst(Craft::t('freelink', 'Only allow {type} to be selected if they match the following rules:', [
                'type' => $elementType::pluralLowerDisplayName(),
            ])),
        ]);
    }

    /**
     * Sources as the element select modal wants them: `'*'` or a list of source keys.
     * Settings saved before 5.2 could hold a comma-joined string.
     *
     * @return string|string[]
     */
    private function normalizeSources(mixed $sources): string|array
    {
        if (is_string($sources)) {
            if ($sources === '' || $sources === '*') {
                return '*';
            }

            $sources = explode(',', $sources);
        }

        if (!is_array($sources)) {
            return '*';
        }

        $sources = array_values(array_filter(array_map(fn($source) => trim((string)$source), $sources), fn($source) => $source !== ''));

        return $sources ?: '*';
    }

    /**
     * Returns the enabled link type handles in configured sort order.
     *
     * @return string[]
     */
    public function getEnabledTypeHandles(): array
    {
        $enabled = [];

        foreach ($this->linkTypes as $handle => $config) {
            if (!empty($config['enabled'])) {
                $enabled[$config['sortOrder'] ?? 999] = $handle;
            }
        }

        ksort($enabled);

        return array_values($enabled);
    }

    // endregion

    // region Value normalization

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): LinkCollection
    {
        if ($value instanceof LinkCollection) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $value = Json::decodeIfJson($value);
        }

        if (empty($value)) {
            return new LinkCollection();
        }

        // Wrap single link in array for uniform processing
        if (isset($value['type'])) {
            $value = [$value];
        }

        $linksService = Plugin::getInstance()->links;
        $relations = [];

        // Load element relations from the relations table
        if ($element && $element->id) {
            $siteId = $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
            $relations = Plugin::getInstance()->relations->getRelations(
                $this->id,
                $element->id,
                $siteId,
            );
        }

        $links = [];
        foreach ($value as $sortOrder => $linkData) {
            if (!is_array($linkData)) {
                continue;
            }

            $type = $linkData['type'] ?? '';

            // The CP form posts type-specific inputs under keys namespaced by
            // handle: values[<type>] for scalar link types and elements[<type>]
            // for element selectors (see field/_link-block.twig). Map that POST
            // shape onto the flat value/targetId keys createLink() expects.
            // Their presence also marks this as a form submission, in which case
            // the POST is authoritative over the relations table below.
            $isPost = isset($linkData['values']) || isset($linkData['elements']);

            // GraphQL/MCP/PHP input names the target with `targetId` (or `elementId`). Stored JSON
            // never contains either key, so their presence marks input the same way (an explicit
            // null clears the target). An element link given without either keeps the target
            // already stored at its position.
            if (!array_key_exists('targetId', $linkData) && array_key_exists('elementId', $linkData)) {
                $linkData['targetId'] = $linkData['elementId'];
            }
            if (array_key_exists('targetId', $linkData)) {
                $isPost = true;
            }
            if (isset($linkData['htmlId']) && !isset($linkData['id'])) {
                $linkData['id'] = $linkData['htmlId'];
            }
            if (!isset($linkData['value']) && isset($linkData['values']) && is_array($linkData['values'])) {
                $linkData['value'] = $linkData['values'][$type] ?? null;
            }
            if (!isset($linkData['targetId']) && isset($linkData['elements']) && is_array($linkData['elements'])) {
                $selected = $linkData['elements'][$type] ?? null;
                $linkData['targetId'] = is_array($selected) ? ($selected[0] ?? null) : $selected;
            }

            // On a DB load (not a POST), the relations table is the source of
            // truth for which element an element link points at.
            $typeClass = $linksService->getTypeByHandle($type);
            if (!$isPost && $typeClass && is_subclass_of($typeClass, ElementLink::class)) {
                if (isset($relations[$sortOrder])) {
                    $linkData['targetId'] = $relations[$sortOrder]['targetId'];
                    $linkData['targetSiteId'] = $relations[$sortOrder]['targetSiteId'];
                }
            }

            // Custom attributes are an Advanced setting. With Advanced off the editor can't see them,
            // so whatever is stored or posted is not theirs to render.
            if (!$this->showAdvanced) {
                $linkData['customAttributes'] = [];
            }

            // Same for icons: a field with icons off has none, whatever was stored before.
            if (!$this->allowIcons) {
                unset($linkData['icon']);
            }

            $link = $linksService->createLink($linkData);

            if ($link && $element?->siteId) {
                $link->ownerSiteId = (int)$element->siteId;
            }

            if ($link) {
                $links[] = $link;
            }
        }

        return new LinkCollection($links);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof LinkCollection) {
            return null;
        }

        if ($value->isEmpty()) {
            return null;
        }

        $serialized = [];
        foreach ($value->getAll() as $link) {
            $serialized[] = $link->toArray();
        }

        // Single-link mode: store as object, not array
        if (!$this->multipleLinks && count($serialized) === 1) {
            return $serialized[0];
        }

        return $serialized;
    }

    // endregion

    // region Element lifecycle

    /**
     * The security half of link validation, on every save — drafts, provisional drafts and
     * essentials saves included, and saves that skip validation.
     *
     * Field validation (validateLinks()) only runs on live saves, so without this an editor could
     * save a draft linking to an element they can't view, and the draft's editor or preview would
     * show that element's title and URL. Each element link that isn't unchanged from the live
     * content (storedLinks()) must point at an element the saving user (or GraphQL schema) can
     * view. Min/max, required, selection conditions and the other checks stay live-only.
     */
    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        if (!parent::beforeElementSave($element, $isNew)) {
            return false;
        }

        if (!$element->isFieldDirty($this->handle) && !$isNew) {
            // Nothing new was set on this field in this request.
            return true;
        }

        $value = $element->getFieldValue($this->handle);

        if (!$value instanceof LinkCollection) {
            return true;
        }

        $stored = null;
        $ok = true;

        foreach ($value->getAll() as $index => $link) {
            if (!$link instanceof ElementLink || !$link->targetId) {
                continue;
            }

            $stored ??= $this->storedLinks($element);

            if (isset($stored[self::linkKey($link)])) {
                continue;
            }

            if (!$this->actorMayLinkTo($link, $element)) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute}: you can’t link to the {type} chosen for link {num}.', [
                    'attribute' => $this->name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $index + 1,
                ]));
                $ok = false;
            }
        }

        return $ok;
    }


    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        $value = $element->getFieldValue($this->handle);

        if (!$value instanceof LinkCollection) {
            parent::afterElementSave($element, $isNew);
            return;
        }

        $siteId = $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $relations = [];

        foreach ($value->getAll() as $sortOrder => $link) {
            if ($link instanceof ElementLink && $link->targetId) {
                $relations[] = [
                    'sortOrder' => $sortOrder,
                    'targetId' => $link->targetId,
                    'targetSiteId' => $link->targetSiteId,
                ];
            }
        }

        // A target deleted since the value was loaded (or an ID that never existed, on a save that
        // skipped validation) would fail the foreign key and abort the whole save. Drop it instead:
        // the link loads as empty, as it would after the target's deletion anyway.
        if ($relations) {
            $existing = array_map('intval', (new \craft\db\Query())
                ->select(['id'])
                ->from(\craft\db\Table::ELEMENTS)
                ->where(['id' => array_column($relations, 'targetId')])
                ->column());
            $relations = array_values(array_filter($relations, fn($r) => in_array((int)$r['targetId'], $existing, true)));
        }

        $relationsService = Plugin::getInstance()->relations;

        if (!empty($relations)) {
            $relationsService->saveRelations($this->id, $element->id, $siteId, $relations);
        } else {
            $relationsService->deleteRelations($this->id, $element->id, $siteId);
        }

        // Keep the link index (Links screen, reverse lookups, checks) in step with live content.
        try {
            Plugin::getInstance()->linkIndex->indexValue($this, $element, $value);
        } catch (\Throwable $e) {
            // The index is derived data; never fail a save over it.
            Craft::warning("FreeLink couldn't index field \"$this->handle\" on element $element->id: {$e->getMessage()}", __METHOD__);
        }

        parent::afterElementSave($element, $isNew);
    }

    public function afterElementDelete(ElementInterface $element): void
    {
        // Relations are cleaned up by CASCADE foreign key on ownerId,
        // but explicit cleanup for safety
        $siteId = $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        Plugin::getInstance()->relations->deleteRelations($this->id, $element->id, $siteId);

        parent::afterElementDelete($element);
    }

    // endregion

    // region Input

    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        if (!$value instanceof LinkCollection) {
            $value = new LinkCollection();
        }

        $linksService = Plugin::getInstance()->links;
        $enabledHandles = $this->getEnabledTypeHandles();

        $typeOptions = [];
        foreach ($enabledHandles as $handle) {
            $class = $linksService->getTypeByHandle($handle);
            if ($class) {
                $label = ($this->linkTypes[$handle]['label'] ?? '') ?: $class::displayName();
                $isElement = is_subclass_of($class, ElementLink::class);

                $condition = $isElement ? $this->getSelectionCondition($handle) : null;
                if ($condition instanceof ElementCondition) {
                    $condition->referenceElement = $element;
                }

                $typeOptions[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'isElement' => $isElement,
                    'sources' => $isElement ? $this->normalizeSources($this->linkTypes[$handle]['sources'] ?? '*') : '*',
                    'condition' => $condition,
                ];
            }
        }

        // Ensure there's at least one link for the input
        $links = $value->getAll();
        if (empty($links)) {
            $defaultType = $this->defaultLinkType;
            $link = $linksService->createLink([
                'type' => $defaultType,
                'newWindow' => $this->defaultNewWindow,
            ]);
            $links = $link ? [$link] : [];
        }

        $id = $this->getInputId();
        $namespacedId = Craft::$app->getView()->namespaceInputId($id);

        Craft::$app->getView()->registerAssetBundle(\justinholtweb\freelink\web\assets\field\FieldAsset::class);

        return Craft::$app->getView()->renderTemplate('freelink/field/input', [
            'id' => $id,
            'namespacedId' => $namespacedId,
            'name' => $this->handle,
            'field' => $this,
            'element' => $element,
            'value' => $value,
            'links' => $links,
            'typeOptions' => $typeOptions,
            'multipleLinks' => $this->multipleLinks,
            'showLabel' => $this->showLabel,
            'showNewWindow' => $this->showNewWindow,
            'showAdvanced' => $this->showAdvanced,
            'minLinks' => $this->minLinks,
            'maxLinks' => $this->maxLinks,
            'defaultLinkType' => $this->defaultLinkType,
            'defaultNewWindow' => $this->defaultNewWindow,
        ]);
    }

    // endregion

    // region Validation

    /**
     * @return array<int, mixed>
     */
    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();

        $rules[] = [
            'validateLinks',
            'on' => [Element::SCENARIO_LIVE],
        ];

        return $rules;
    }

    public function validateLinks(ElementInterface $element): void
    {

        /** @var LinkCollection $value */
        $value = $element->getFieldValue($this->handle);

        if (!$value instanceof LinkCollection) {
            return;
        }

        // Required field check
        if ($this->required && $value->isEmpty()) {
            $element->addError($this->handle, Craft::t('freelink', '{attribute} cannot be blank.', [
                'attribute' => $this->name,
            ]));
            return;
        }

        // Multi-link count validation
        if ($this->multipleLinks) {
            $count = $value->count();

            if ($this->minLinks && $count < $this->minLinks) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute} must have at least {min} links.', [
                    'attribute' => $this->name,
                    'min' => $this->minLinks,
                ]));
            }

            if ($this->maxLinks && $count > $this->maxLinks) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute} must have no more than {max} links.', [
                    'attribute' => $this->name,
                    'max' => $this->maxLinks,
                ]));
            }
        }

        $enabledTypes = $this->getEnabledTypeHandles();
        // Links already stored on this element (or its canonical) are grandfathered: the 5.3 checks
        // (enabled type, target existence/visibility, selection condition, icon) apply only to new or
        // changed links, so tightening a field never blocks saving existing content.
        $stored = $this->storedLinks($element);
        $deletedTargetPositions = $this->deletedTargetPositions($element);

        // Validate individual links
        foreach ($value->getAll() as $index => $link) {
            $num = $index + 1;
            $storedLink = $stored[self::linkKey($link)] ?? null;
            $unchanged = $storedLink !== null;

            // The CP only offers enabled types, but a POST, GraphQL or MCP can name any type.
            if (!$unchanged && $enabledTypes && !in_array($link->type, $enabledTypes, true)) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute}: link {num} uses the “{type}” link type, which this field doesn’t allow.', [
                    'attribute' => $this->name,
                    'num' => $num,
                    'type' => $link->type,
                ]));
                continue;
            }

            if ($link->icon !== null) {
                if (!$this->allowIcons) {
                    $link->icon = null;
                } elseif (!($unchanged && $storedLink->icon === $link->icon) && !Icons::exists($link->icon, $this->includeProIcons)) {
                    $element->addError($this->handle, Craft::t('freelink', '{attribute}: the icon for link {num} isn’t available.', [
                        'attribute' => $this->name,
                        'num' => $num,
                    ]));
                }
            }

            if ($link instanceof ElementLink && $link->targetId && !$this->linkTargetExists($link, $element)) {
                if (in_array((int)$index, $deletedTargetPositions, true)) {
                    // The stored target was deleted since this value was loaded: the link becomes
                    // empty, as it does on the next load anyway.
                    $link->targetId = null;
                    $link->targetSiteId = null;
                    continue;
                }

                $element->addError($this->handle, Craft::t('freelink', '{attribute}: the {type} chosen for link {num} doesn’t exist.', [
                    'attribute' => $this->name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $num,
                ]));
                continue;
            }

            if (!$unchanged && $link instanceof ElementLink && $link->targetId && !$this->actorMayLinkTo($link, $element)) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute}: you can’t link to the {type} chosen for link {num}.', [
                    'attribute' => $this->name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $num,
                ]));
                continue;
            }

            if (!$link->validate()) {
                foreach ($link->getErrors() as $attribute => $errors) {
                    foreach ($errors as $error) {
                        $element->addError($this->handle, $error);
                    }
                }
            }

            if (!$unchanged && $link instanceof ElementLink && !$this->linkMatchesSelectionCondition($link, $element)) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute}: the {type} chosen for link {num} isn’t allowed here.', [
                    'attribute' => $this->name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $index + 1,
                ]));
            }
        }
    }

    /**
     * What makes two links "the same link" for grandfathering: type and destination. Label, new
     * window, icon and the advanced attributes can change without the link counting as new.
     */
    public static function linkKey(Link $link): string
    {
        if ($link instanceof ElementLink) {
            return $link->type . '|#' . (int)$link->targetId . '|' . (int)$link->targetSiteId;
        }

        $key = $link->type . '|' . (string)$link->value;

        if ($link instanceof \justinholtweb\freelink\links\Site) {
            $key .= '|' . (int)$link->siteId;
        }

        return $key;
    }

    /**
     * The links stored for this field in the element's live content, keyed by linkKey(): the
     * canonical element as saved (never a draft, provisional draft or revision). Only that content
     * passed live validation, so it is the only baseline a link can be grandfathered against. A new
     * element, or a draft of one, has none.
     *
     * @return array<string, Link>
     */
    public function storedLinks(ElementInterface $element): array
    {
        $canonicalId = $element->getCanonicalId();

        if (!$canonicalId) {
            return [];
        }

        $siteId = $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $stored = $element::find()
            ->id($canonicalId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->provisionalDrafts(false)
            ->revisions(false)
            ->one();

        if (!$stored) {
            return [];
        }

        try {
            $value = $stored->getFieldValue($this->handle);
        } catch (\Throwable) {
            return [];
        }

        $links = [];

        if ($value instanceof LinkCollection) {
            foreach ($value->getAll() as $link) {
                $links[self::linkKey($link)] ??= $link;
            }
        }

        return $links;
    }

    /**
     * Positions whose stored element target was deleted (the relation row survives with a null
     * target after the foreign key's SET NULL).
     *
     * @return int[]
     */
    private function deletedTargetPositions(ElementInterface $element): array
    {
        if (!$element->id || !$this->id) {
            return [];
        }

        return array_map('intval', (new \craft\db\Query())
            ->select(['sortOrder'])
            ->from('{{%freelink_links}}')
            ->where([
                'fieldId' => $this->id,
                'ownerId' => array_unique(array_filter([$element->id, $element->getCanonicalId()])),
                'ownerSiteId' => $element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
                'targetId' => null,
            ])
            ->column());
    }

    /**
     * Whether an element link's target exists and is the link type's element type, in any status.
     */
    public function linkTargetExists(ElementLink $link, ?ElementInterface $owner = null): bool
    {
        if (!$link->targetId) {
            return true;
        }

        $elementType = $link::elementType();

        if (!class_exists($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
            return false;
        }

        $query = $elementType::find()->id($link->targetId)->status(null);

        if ($link->targetSiteId) {
            $query->siteId($link->targetSiteId);
        } else {
            $query->site('*')->unique();
        }

        return $query->exists();
    }

    /**
     * Whether whoever is saving may point a link at this target.
     *
     * validateLinks() only calls this for new or changed links (see storedLinks()), so an editor can
     * still save live content that links to something someone else chose. A signed-in user (CP,
     * MCP) must be able to view the target; a GraphQL schema must be able to query it. Otherwise a token or agent could link to an
     * element it can't see and read its URL and title back from the link.
     */
    public function actorMayLinkTo(ElementLink $link, ElementInterface $owner): bool
    {
        if (!$link->targetId) {
            return true;
        }

        // Check the copy the link will resolve to (see ElementLink::resolvedSiteId()).
        if (!$link->ownerSiteId && $owner->siteId) {
            $link->ownerSiteId = (int)$owner->siteId;
        }

        if (GqlScope::isActive() && !GqlScope::canQuery((int)$link->targetId, $link->resolvedSiteId())) {
            return false;
        }

        try {
            $user = Craft::$app->getUser()->getIdentity();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user) {
            return true;
        }

        // The copy the link resolves to: if there is none, there is nothing the user may see.
        $target = $link->getElement();

        return $target !== null && Craft::$app->getElements()->canView($target, $user);
    }

    /**
     * Whether an element link's target matches its type's selection condition.
     *
     * The modal only narrows what an editor is offered. A POST can name any element ID, so the
     * condition is checked again here against the element itself.
     */
    public function linkMatchesSelectionCondition(ElementLink $link, ?ElementInterface $owner = null): bool
    {
        if (!$link->targetId) {
            return true;
        }

        $condition = $this->getSelectionCondition($link->type ?: $link::handle());

        if (!$condition) {
            return true;
        }

        $target = $link->getElement();

        if (!$target && !$link->targetSiteId && $owner?->siteId) {
            // No site recorded and nothing in the current one: look in the owner's site.
            $elementType = $link::elementType();
            $target = $elementType::find()->id($link->targetId)->siteId($owner->siteId)->status(null)->one();
        }

        if (!$target) {
            // A target that doesn't exist can't match. Saving would only store a dead link.
            return false;
        }

        if ($condition instanceof ElementCondition) {
            $condition->referenceElement = $owner;
        }

        return $condition->matchElement($target);
    }

    // endregion

    // region GraphQL

    /**
     * @return \GraphQL\Type\Definition\Type|array<string, mixed>
     */
    public function getContentGqlType(): \GraphQL\Type\Definition\Type|array
    {
        return \justinholtweb\freelink\gql\types\generators\LinkTypeGenerator::generateType($this);
    }

    /**
     * `FreeLinkInput` (single-link mode) or `[FreeLinkInput]` (multi-link mode). Values go through
     * normalizeValue() and the same validation as a CP save.
     *
     * @return \GraphQL\Type\Definition\Type|array<string, mixed>
     */
    public function getContentGqlMutationArgumentType(): \GraphQL\Type\Definition\Type|array
    {
        $type = LinkInput::getType();

        return [
            'name' => $this->handle,
            'type' => $this->multipleLinks ? \GraphQL\Type\Definition\Type::listOf($type) : $type,
            'description' => $this->instructions,
        ];
    }

    // endregion

    // region MCP (Craft 5.12+)

    /**
     * JSON schema of the values this field accepts (core MCP element tools). Built from the field's
     * settings: only enabled link types, only the inputs the field shows.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        $links = Plugin::getInstance()->links;
        $enabled = $this->getEnabledTypeHandles() ?: ['url'];
        $elementTypes = [];

        foreach ($enabled as $handle) {
            $class = $links->getTypeByHandle($handle);
            if ($class && is_subclass_of($class, ElementLink::class)) {
                $elementTypes[] = $handle;
            }
        }

        $nullableString = ['type' => ['string', 'null']];
        $properties = [
            'type' => ['type' => 'string', 'enum' => array_values($enabled)],
            'value' => $nullableString + ['description' => 'URL, email address, phone number, custom value or site path. Omit for element link types.'],
            'targetId' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Element ID for ' . ($elementTypes ? implode('/', $elementTypes) : 'element') . ' links. Omit to keep the target already at this position; null clears it.'],
            'targetSiteId' => ['type' => ['integer', 'null']],
            'siteId' => ['type' => ['integer', 'null'], 'description' => 'For type site.'],
            'subject' => $nullableString + ['description' => 'For type email.'],
        ];

        if ($this->showLabel) {
            $properties['label'] = $nullableString;
        }

        if ($this->showNewWindow) {
            $properties['newWindow'] = ['type' => 'boolean'];
        }

        if ($this->showAdvanced) {
            foreach (['ariaLabel', 'title', 'urlSuffix', 'classes', 'id', 'rel'] as $key) {
                $properties[$key] = $nullableString;
            }
            $properties['customAttributes'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['attribute'],
                    'properties' => ['attribute' => ['type' => 'string'], 'value' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ];
        }

        if ($this->allowIcons) {
            $properties['icon'] = $nullableString + ['description' => 'Icon name from Craft’s icon set' . ($this->includeProIcons ? '' : ' (free Font Awesome icons only)') . '.'];
        }

        $link = [
            'type' => 'object',
            'required' => ['type'],
            'additionalProperties' => false,
            'properties' => $properties,
        ];

        $description = 'FreeLink. Enabled link types: ' . implode(', ', $enabled) . '.';

        if (!$this->multipleLinks) {
            return $link + ['description' => $description];
        }

        $schema = ['type' => 'array', 'items' => $link, 'description' => $description];
        if ($this->minLinks) {
            $schema['minItems'] = $this->minLinks;
        }
        if ($this->maxLinks) {
            $schema['maxItems'] = $this->maxLinks;
        }

        return $schema;
    }

    /**
     * The value as MCP tools return it: resolved links, with the live target element under `target`
     * so core applies the viewer's permissions to it (a hidden target drops that link).
     */
    public function getMcpValue(mixed $value, ElementInterface $element, \craft\mcp\McpOutputContext $context): mixed
    {
        if (!$value instanceof LinkCollection) {
            return $this->multipleLinks ? [] : null;
        }

        $out = [];

        foreach ($value->getAll() as $link) {
            // Core also drops links whose `target` element the viewer can't see; this keeps the
            // target's URL and title out even if it didn't.
            $visible = $context->public || TargetVisibility::canSee($link);
            $data = [
                'type' => $link->type,
                'url' => $visible ? $link->getUrl() : null,
                'text' => $visible ? $link->getText() : ($link->label ?: null),
            ];

            if ($link instanceof ElementLink) {
                $target = $link->getElement();

                if ($context->public) {
                    // Public server: no element, and nothing for a target that isn't live.
                    if (!$target || !in_array($target->getStatus(), [Element::STATUS_ENABLED, 'live'], true)) {
                        continue;
                    }
                    $out[] = $data;
                    continue;
                }

                $data['targetId'] = $link->targetId;
                $data['targetSiteId'] = $link->targetSiteId;
                $data['target'] = $visible ? $target : null;
            }

            $raw = $link->toArray();
            unset($raw['type']);
            if ($link instanceof ElementLink) {
                unset($raw['value']);
            }

            $out[] = $data + $raw;
        }

        if ($this->multipleLinks) {
            return $out;
        }

        return $out[0] ?? null;
    }

    // endregion

    // region Search

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof LinkCollection) {
            return '';
        }

        $keywords = [];

        foreach ($value->getAll() as $link) {
            if ($link->label) {
                $keywords[] = $link->label;
            }
            if ($link->value) {
                $keywords[] = $link->value;
            }
        }

        return implode(' ', $keywords);
    }

    // endregion
}

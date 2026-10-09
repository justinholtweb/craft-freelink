<?php

namespace justinholtweb\freelink\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\conditions\ElementCondition;
use craft\elements\conditions\ElementConditionInterface;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\Plugin;

class FreeLinkField extends Field
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

            $link = $linksService->createLink($linkData);

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

        $relationsService = Plugin::getInstance()->relations;

        if (!empty($relations)) {
            $relationsService->saveRelations($this->id, $element->id, $siteId, $relations);
        } else {
            $relationsService->deleteRelations($this->id, $element->id, $siteId);
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

        // Validate individual links
        foreach ($value->getAll() as $index => $link) {
            if (!$link->validate()) {
                foreach ($link->getErrors() as $attribute => $errors) {
                    foreach ($errors as $error) {
                        $element->addError($this->handle, $error);
                    }
                }
            }

            if ($link instanceof ElementLink && !$this->linkMatchesSelectionCondition($link, $element)) {
                $element->addError($this->handle, Craft::t('freelink', '{attribute}: the {type} chosen for link {num} isn’t allowed here.', [
                    'attribute' => $this->name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $index + 1,
                ]));
            }
        }
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

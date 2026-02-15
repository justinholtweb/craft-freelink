<?php

namespace justinholtweb\freelink\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\EagerLoadingFieldInterface;
use craft\helpers\Json;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\Plugin;

class FreeLinkField extends Field implements EagerLoadingFieldInterface
{
    // region Settings

    /**
     * Configured link types. Array of type handle => type config:
     * ['enabled' => bool, 'label' => string, 'sources' => string|array, 'sortOrder' => int]
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

    // endregion

    public static function displayName(): string
    {
        return 'FreeLink';
    }

    public static function icon(): string
    {
        return 'link';
    }

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
            $typeOptions[] = [
                'handle' => $handle,
                'label' => $class::displayName(),
                'isElement' => is_subclass_of($class, ElementLink::class),
                'enabled' => $this->linkTypes[$handle]['enabled'] ?? ($handle === 'url'),
                'customLabel' => $this->linkTypes[$handle]['label'] ?? '',
                'sources' => $this->linkTypes[$handle]['sources'] ?? '*',
            ];
        }

        return Craft::$app->getView()->renderTemplate('freelink/field/settings', [
            'field' => $this,
            'typeOptions' => $typeOptions,
        ]);
    }

    /**
     * Returns the enabled link type handles in configured sort order.
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

            // Inject element relation data from the relations table
            $typeClass = $linksService->getTypeByHandle($linkData['type'] ?? '');
            if ($typeClass && is_subclass_of($typeClass, ElementLink::class)) {
                if (isset($relations[$sortOrder])) {
                    $linkData['targetId'] = $relations[$sortOrder]['targetId'];
                    $linkData['targetSiteId'] = $relations[$sortOrder]['targetSiteId'];
                }
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
            return Json::encode($serialized[0]);
        }

        return Json::encode($serialized);
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

    // region Eager loading

    public function getEagerLoadingMap(array $sourceElements): array|null|false
    {
        $sourceElementIds = array_map(fn(ElementInterface $el) => $el->id, $sourceElements);

        $map = Plugin::getInstance()->relations->getEagerLoadingMap($this->id, $sourceElementIds);

        if (empty($map)) {
            return null;
        }

        // Determine the element types involved
        $elementTypes = [];
        foreach ($this->getEnabledTypeHandles() as $handle) {
            $class = Plugin::getInstance()->links->getTypeByHandle($handle);
            if ($class && is_subclass_of($class, ElementLink::class)) {
                $elementTypes[] = $class::elementType();
            }
        }

        // Use the first element type as the primary; Craft uses this to query elements
        $elementType = $elementTypes[0] ?? null;

        if (!$elementType) {
            return null;
        }

        return [
            'elementType' => $elementType,
            'map' => $map,
        ];
    }

    public function eagerLoadingMap(array $sourceElements): array|null|false
    {
        return $this->getEagerLoadingMap($sourceElements);
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
                $label = $this->linkTypes[$handle]['customLabel'] ?? $class::displayName();
                $typeOptions[] = [
                    'handle' => $handle,
                    'label' => $label,
                    'isElement' => is_subclass_of($class, ElementLink::class),
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

    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();

        $rules[] = [
            'validateLinks',
            'on' => [ElementInterface::SCENARIO_LIVE],
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
        foreach ($value->getAll() as $link) {
            if (!$link->validate()) {
                foreach ($link->getErrors() as $attribute => $errors) {
                    foreach ($errors as $error) {
                        $element->addError($this->handle, $error);
                    }
                }
            }
        }
    }

    // endregion

    // region GraphQL

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

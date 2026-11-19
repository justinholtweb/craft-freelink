<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Fields;

use Closure;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Conditions\Contracts\ElementConditionInterface;
use CraftCms\Cms\Element\Conditions\ElementCondition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\Validation\ElementRules;
use CraftCms\Cms\Field\Contracts\InputSchemaFieldInterface;
use CraftCms\Cms\Field\Contracts\McpOutputFieldInterface;
use CraftCms\Cms\Field\Contracts\PreviewableFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Mcp\McpOutputContext;
use CraftCms\Cms\Support\Arr;
use CraftCms\Cms\Support\Facades\Conditions;
use CraftCms\Cms\Support\Facades\ElementSources;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\ConditionBuilder;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table as TableControl;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Nodes\Field as UiField;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Base\Link;
use JustinHoltWeb\FreeLink\Database\Tables;
use JustinHoltWeb\FreeLink\Gql\GqlScope;
use JustinHoltWeb\FreeLink\Gql\Types\Input\LinkInput;
use JustinHoltWeb\FreeLink\Gql\Types\LinkTypeGenerator;
use JustinHoltWeb\FreeLink\Helpers\Icons;
use JustinHoltWeb\FreeLink\Links\Site;
use JustinHoltWeb\FreeLink\Models\LinkCollection;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;
use JustinHoltWeb\FreeLink\Services\Relations;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The FreeLink field type.
 *
 * Hybrid storage: the value's JSON lives in the element's content (Craft 6: `elements_sites.content`,
 * keyed by the layout element's UID), and element links' targets live in `freelink_links`, which is
 * what loads them. Every live save also writes the link index (`freelink_index`).
 */
class FreeLinkField extends Field implements InputSchemaFieldInterface, McpOutputFieldInterface, PreviewableFieldInterface
{
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
     * Selection conditions built from `linkTypes[*][selectionCondition]`, keyed by type handle +
     * config. Built lazily: creating a condition can load every field, and a field rule in one of
     * these conditions would otherwise recurse back here while the fields are being loaded.
     *
     * @var array<string, ElementConditionInterface|null>
     */
    private array $selectionConditions = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct($config = [])
    {
        if (is_array($config)) { // @phpstan-ignore function.alreadyNarrowedType (callers pass anything)
            $config = self::assembleSettingsFromUi($config);

            foreach (['multipleLinks', 'showLabel', 'showNewWindow', 'showAdvanced', 'defaultNewWindow', 'allowIcons', 'includeProIcons'] as $key) {
                if (array_key_exists($key, $config) && ! is_bool($config[$key])) {
                    $config[$key] = filter_var($config[$key], FILTER_VALIDATE_BOOLEAN);
                }
            }

            foreach (['minLinks', 'maxLinks'] as $key) {
                if (array_key_exists($key, $config)) {
                    $config[$key] = max(0, (int) $config[$key]);
                }
            }

            if (array_key_exists('defaultLinkType', $config) && ! is_string($config['defaultLinkType'])) {
                $config['defaultLinkType'] = 'url';
            }
        }

        parent::__construct($config);
    }

    #[Override]
    public static function displayName(): string
    {
        return 'FreeLink';
    }

    #[Override]
    public static function icon(): string
    {
        return 'link';
    }

    #[Override]
    public static function phpType(): string
    {
        return sprintf('\\%s', LinkCollection::class);
    }

    /**
     * The value is a JSON object (single mode) or list (multi mode); its shape varies by link type,
     * so it's stored whole rather than as typed sub-columns.
     */
    #[Override]
    public static function dbType(): string
    {
        return Query::TYPE_JSON;
    }

    #[Override]
    public function useFieldset(): bool
    {
        return true;
    }

    // Settings
    // -------------------------------------------------------------------------

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'minLinks' => ['integer', 'min:0'],
            'maxLinks' => ['integer', 'min:0'],
            'defaultLinkType' => ['string'],
        ]);
    }

    /**
     * The settings form. Its instructions are also what Craft 6's MCP `component_types_get` tool
     * describes the field's configuration with.
     */
    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $links = app(Links::class);
        $available = $links->getAvailableTypes();
        $enabled = array_values(array_filter($this->getEnabledTypeHandles(), fn ($h) => isset($available[$h])));
        $typeOptions = [];
        $labelRows = [];

        foreach ($available as $handle => $class) {
            $typeOptions[] = ['label' => $class::displayName(), 'value' => $handle];
            $labelRows[] = [
                'handle' => $handle,
                'type' => $class::displayName(),
                'label' => (string) ($this->linkTypes[$handle]['label'] ?? ''),
            ];
        }

        $nodes = [
            Heading::make('freelink-types', t('Link types', category: 'freelink')),
            UiField::make(t('Link Types', category: 'freelink'))
                ->instructions(t('The link types editors can choose from, in the order they’re offered. Drag to reorder. Element link types (entry, asset, user) store the element’s ID; the others store text (a URL, email address, phone number, custom value or a path on one of your sites).', category: 'freelink'))
                ->control(Choice::make('enabledTypes')->options($typeOptions)->sortable()->value($enabled ?: ['url'])),
            UiField::make(t('Default Link Type', category: 'freelink'))
                ->instructions(t('The type a new, empty link starts as. Must be one of the enabled types.', category: 'freelink'))
                ->control(Choice::make('defaultLinkType')->options($typeOptions)->value($this->defaultLinkType)),
            UiField::make(t('Link Type Labels', category: 'freelink'))
                ->instructions(t('Optional names editors see instead of the built-in ones (e.g. “Page” for Entry).', category: 'freelink'))
                ->control(TableControl::make('typeLabels')->columns([
                    'handle' => ['type' => 'hidden'],
                    'type' => ['heading' => t('Type', category: 'freelink'), 'type' => 'heading'],
                    'label' => ['heading' => t('Label', category: 'freelink'), 'type' => 'singleline'],
                ])->value($labelRows)),
        ];

        foreach ($available as $handle => $class) {
            if (! is_subclass_of($class, ElementLink::class)) {
                continue;
            }

            /** @var class-string<ElementInterface> $elementType */
            $elementType = $class::elementType();
            $sources = $this->sourceOptions($elementType);

            if ($sources !== []) {
                $current = self::normalizeSources($this->linkTypes[$handle]['sources'] ?? '*');
                $nodes[] = UiField::make(t('{type} Sources', ['type' => $elementType::displayName()], category: 'freelink'))
                    ->instructions(t('Where editors can pick {type} from, when the {type} link type is enabled.', ['type' => $elementType::pluralLowerDisplayName()], category: 'freelink'))
                    ->control(Choice::make(['typeSources', $handle])
                        ->multiple()
                        ->presentation(ChoicePresentation::Checkboxes)
                        ->options([['label' => t('All', category: 'freelink'), 'value' => '*'], ...$sources])
                        ->value($current === '*' ? ['*'] : $current));
            }

            $condition = $this->getSelectionCondition($handle) ?? $elementType::createCondition();
            $nodes[] = UiField::make(t('Selectable {type} Condition', ['type' => $elementType::pluralDisplayName()], category: 'freelink'))
                ->instructions(mb_ucfirst(t('Only allow {type} to be selected if they match the following rules:', ['type' => $elementType::pluralLowerDisplayName()], category: 'freelink')))
                ->control(ConditionBuilder::make(['typeConditions', $handle])
                    ->conditionClass($condition::class)
                    ->queryParams(['site'])
                    ->forProjectConfig()
                    ->value($condition->getConfig()));
        }

        array_push($nodes,
            Heading::make('freelink-links', t('Links', category: 'freelink')),
            UiField::make(t('Allow Multiple Links', category: 'freelink'))
                ->instructions(t('Let editors add more than one link. The value is then a list of links; otherwise it is a single link.', category: 'freelink'))
                ->control(Lightswitch::make('multipleLinks')->value($this->multipleLinks)),
            UiField::make(t('Min Links', category: 'freelink'))
                ->instructions(t('With multiple links: the fewest an editor may save. 0 for no minimum.', category: 'freelink'))
                ->control(Number::make('minLinks')->min(0)->value($this->minLinks)),
            UiField::make(t('Max Links', category: 'freelink'))
                ->instructions(t('With multiple links: the most an editor may save. 0 for no limit.', category: 'freelink'))
                ->control(Number::make('maxLinks')->min(0)->value($this->maxLinks)),
            UiField::make(t('Show Label', category: 'freelink'))
                ->instructions(t('Let editors give each link its own text.', category: 'freelink'))
                ->control(Lightswitch::make('showLabel')->value($this->showLabel)),
            UiField::make(t('Show “Open in New Window”', category: 'freelink'))
                ->control(Lightswitch::make('showNewWindow')->value($this->showNewWindow)),
            UiField::make(t('Open New Links in a New Window', category: 'freelink'))
                ->instructions(t('The default for a new link.', category: 'freelink'))
                ->control(Lightswitch::make('defaultNewWindow')->value($this->defaultNewWindow)),
            UiField::make(t('Show Advanced Settings', category: 'freelink'))
                ->instructions(t('Let editors set the URL suffix, title, CSS classes, ID, rel, ARIA label and custom attributes of each link. With this off, stored custom attributes aren’t rendered.', category: 'freelink'))
                ->control(Lightswitch::make('showAdvanced')->value($this->showAdvanced)),
            Heading::make('freelink-icons', t('Icons', category: 'freelink')),
            UiField::make(t('Allow Icons', category: 'freelink'))
                ->instructions(t('Let editors pick an icon for each link from Craft’s icon set. Templates output it with `link.iconSvg`.', category: 'freelink'))
                ->control(Lightswitch::make('allowIcons')->value($this->allowIcons)),
            UiField::make(t('Include Font Awesome Pro Icons', category: 'freelink'))
                ->instructions(t('Craft’s Pro icons are licensed for the control panel only. Turn this on only if the site has its own Font Awesome Pro licence, since link icons render on the front end.', category: 'freelink'))
                ->control(Lightswitch::make('includeProIcons')->value($this->includeProIcons)),
        );

        return Ui::make($nodes);
    }

    /**
     * Turns what the settings form posts (`enabledTypes`, `typeLabels`, `typeSources`,
     * `typeConditions`) into `linkTypes`. Configs without those keys (project config, PHP) pass
     * through unchanged.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function assembleSettingsFromUi(array $config): array
    {
        $keys = ['enabledTypes', 'typeLabels', 'typeSources', 'typeConditions'];

        if (! array_intersect($keys, array_keys($config))) {
            return $config;
        }

        $enabled = array_values(array_filter((array) Arr::pull($config, 'enabledTypes', []), 'is_string'));
        $labelRows = (array) Arr::pull($config, 'typeLabels', []);
        $sources = (array) Arr::pull($config, 'typeSources', []);
        $conditions = (array) Arr::pull($config, 'typeConditions', []);

        $labels = [];
        foreach ($labelRows as $key => $row) {
            if (is_array($row)) {
                $handle = $row['handle'] ?? (is_string($key) ? $key : null);
                if (is_string($handle) && $handle !== '') {
                    $labels[$handle] = trim((string) ($row['label'] ?? ''));
                }
            }
        }

        $existing = is_array($config['linkTypes'] ?? null) ? $config['linkTypes'] : [];
        $linkTypes = [];
        $order = 100;

        foreach (app(Links::class)->getAvailableTypes() as $handle => $class) {
            $position = array_search($handle, $enabled, true);
            $type = [
                'enabled' => $position !== false,
                'label' => $labels[$handle] ?? (string) ($existing[$handle]['label'] ?? ''),
                'sortOrder' => $position !== false ? $position : $order++,
            ];

            if (is_subclass_of($class, ElementLink::class)) {
                $type['sources'] = self::normalizeSources($sources[$handle] ?? ($existing[$handle]['sources'] ?? '*'));

                $condition = $conditions[$handle] ?? ($existing[$handle]['selectionCondition'] ?? null);
                if (is_string($condition)) {
                    $condition = Json::decodeIfJson($condition);
                }
                if (is_array($condition) && ! empty($condition['conditionRules'])) {
                    $type['selectionCondition'] = $condition;
                }
            }

            $linkTypes[$handle] = $type;
        }

        // Types that aren't available any more (categories, Commerce) keep their settings, so their
        // stored links stay readable.
        foreach ($existing as $handle => $type) {
            if (! isset($linkTypes[$handle]) && is_array($type)) {
                $linkTypes[$handle] = $type;
            }
        }

        $config['linkTypes'] = $linkTypes;

        return $config;
    }

    /**
     * Field settings as they go to project config. Selection conditions are stored as their config,
     * and dropped when they have no rules.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $linkTypes = [];

        foreach ($this->linkTypes as $handle => $config) {
            if (! is_array($config)) { // @phpstan-ignore function.alreadyNarrowedType (hand-edited project config)
                continue;
            }

            if (isset($config['sources'])) {
                $config['sources'] = self::normalizeSources($config['sources']);
            }

            unset($config['selectionCondition']);
            $condition = $this->getSelectionCondition((string) $handle);
            if ($condition) {
                $config['selectionCondition'] = $condition->getConfig();
            }

            $linkTypes[$handle] = $config;
        }

        $settings['linkTypes'] = $linkTypes;

        return $settings;
    }

    /**
     * The condition an element link type's selected element has to match, or null when the type has
     * none (or isn't an element link type).
     *
     * The condition narrows the element select modal and is enforced again when the owner is saved.
     */
    public function getSelectionCondition(string $handle): ?ElementConditionInterface
    {
        $config = $this->linkTypes[$handle]['selectionCondition'] ?? null;

        // Keyed on the config too, so a changed setting is never answered from the old condition.
        $key = $handle.':'.($config instanceof ElementConditionInterface ? spl_object_id($config) : md5((string) json_encode($config)));
        if (array_key_exists($key, $this->selectionConditions)) {
            return $this->selectionConditions[$key];
        }

        $condition = null;
        $elementType = $this->elementTypeFor($handle);

        if ($elementType && $config) {
            try {
                $created = $config instanceof ElementConditionInterface ? $config : Conditions::createCondition($config);

                // Only a condition for this type's own element type counts. Anything else is a
                // stale or hand-edited config, and applying it would filter on the wrong rules.
                if (
                    $created instanceof ElementConditionInterface &&
                    (! $created instanceof ElementCondition || $created->elementType === null || $created->elementType === $elementType) &&
                    ! empty($created->getConditionRules()->getRules())
                ) {
                    $condition = $created;
                }
            } catch (Throwable $e) {
                Log::warning("FreeLink field \"$this->handle\": couldn't build the selection condition for the \"$handle\" link type: {$e->getMessage()}");
            }
        }

        return $this->selectionConditions[$key] = $condition;
    }

    /**
     * The element class an element link type targets, or null for a simple link type.
     *
     * @return class-string<ElementInterface>|null
     */
    private function elementTypeFor(string $handle): ?string
    {
        $class = app(Links::class)->getTypeByHandle($handle);

        if (! $class || ! is_subclass_of($class, ElementLink::class)) {
            return null;
        }

        $elementType = $class::elementType();

        return class_exists($elementType) && is_subclass_of($elementType, ElementInterface::class) ? $elementType : null;
    }

    /**
     * @param  class-string<ElementInterface>  $elementType
     * @return list<array{label: string, value: string}>
     */
    private function sourceOptions(string $elementType): array
    {
        try {
            return ElementSources::getSources($elementType, 'field')
                ->filter(fn (array $source) => isset($source['key'], $source['label']) && ($source['type'] ?? null) !== 'heading')
                ->map(fn (array $source) => ['label' => (string) $source['label'], 'value' => (string) $source['key']])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Sources as the element select modal wants them: `'*'` or a list of source keys.
     * Settings saved before 5.2 could hold a comma-joined string.
     *
     * @return string|string[]
     */
    public static function normalizeSources(mixed $sources): string|array
    {
        if (is_string($sources)) {
            if ($sources === '' || $sources === '*') {
                return '*';
            }

            $sources = explode(',', $sources);
        }

        if (! is_array($sources)) {
            return '*';
        }

        $sources = array_values(array_filter(array_map(fn ($source) => trim(is_scalar($source) ? (string) $source : ''), $sources), fn ($source) => $source !== ''));

        if ($sources === [] || in_array('*', $sources, true)) {
            return '*';
        }

        return $sources;
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
            if (! empty($config['enabled'])) {
                $enabled[] = [(int) ($config['sortOrder'] ?? 999), (string) $handle];
            }
        }

        usort($enabled, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_column($enabled, 1);
    }

    // Value normalization
    // -------------------------------------------------------------------------

    #[Override]
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): LinkCollection
    {
        if ($value instanceof LinkCollection) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        if (empty($value) || ! is_array($value)) {
            return new LinkCollection;
        }

        $links = app(Links::class);

        // Rows posted by the CP's link table.
        if (LinkControl::isControlValue($value)) {
            $value = LinkControl::fromControlRows($value, $links);
        }

        // Wrap single link in array for uniform processing
        if (isset($value['type'])) {
            $value = [$value];
        }

        $relations = [];

        // Load element relations from the relations table
        if ($element && $element->id && $this->id) {
            $relations = app(Relations::class)->getRelations((int) $this->id, (int) $element->id, (int) $element->siteId);
        }

        $result = [];
        foreach (array_values($value) as $sortOrder => $linkData) {
            if (! is_array($linkData)) {
                continue;
            }

            $type = is_string($linkData['type'] ?? null) ? $linkData['type'] : '';

            // The pre-6 CP form posted type-specific inputs under keys namespaced by handle:
            // values[<type>] for scalar link types and elements[<type>] for element selectors.
            $isInput = isset($linkData['values']) || isset($linkData['elements']);

            // GraphQL/MCP/PHP/CP input names the target with `targetId` (or `elementId`). Stored JSON
            // never contains either key, so their presence marks input (an explicit null clears the
            // target). An element link given without either keeps the target already stored at its
            // position.
            if (! array_key_exists('targetId', $linkData) && array_key_exists('elementId', $linkData)) {
                $linkData['targetId'] = $linkData['elementId'];
            }
            if (array_key_exists('targetId', $linkData)) {
                $isInput = true;
            }
            if (isset($linkData['htmlId']) && ! isset($linkData['id'])) {
                $linkData['id'] = $linkData['htmlId'];
            }
            if (! isset($linkData['value']) && isset($linkData['values']) && is_array($linkData['values'])) {
                $linkData['value'] = $linkData['values'][$type] ?? null;
            }
            if (! isset($linkData['targetId']) && isset($linkData['elements']) && is_array($linkData['elements'])) {
                $selected = $linkData['elements'][$type] ?? null;
                $linkData['targetId'] = is_array($selected) ? ($selected[0] ?? null) : $selected;
            }

            // On a DB load (not input), the relations table is the source of truth for which
            // element an element link points at.
            $typeClass = $links->getTypeByHandle($type);
            if (! $isInput && $typeClass && is_subclass_of($typeClass, ElementLink::class) && isset($relations[$sortOrder])) {
                $linkData['targetId'] = $relations[$sortOrder]['targetId'];
                $linkData['targetSiteId'] = $relations[$sortOrder]['targetSiteId'];
            }

            // Custom attributes are an Advanced setting. With Advanced off the editor can't see
            // them, so whatever is stored or posted is not theirs to render.
            if (! $this->showAdvanced) {
                $linkData['customAttributes'] = [];
            }

            // Same for icons: a field with icons off has none, whatever was stored before.
            if (! $this->allowIcons) {
                unset($linkData['icon']);
            }

            $link = $links->createLink($linkData);

            if ($link instanceof ElementLink && $element?->siteId) {
                $link->ownerSiteId = (int) $element->siteId;
            }

            if ($link) {
                $result[] = $link;
            }
        }

        return new LinkCollection($result);
    }

    #[Override]
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (! $value instanceof LinkCollection || $value->isEmpty()) {
            return null;
        }

        $serialized = [];
        foreach ($value->getAll() as $link) {
            $serialized[] = $link->toArray();
        }

        // Single-link mode: store as object, not array
        if (! $this->multipleLinks && count($serialized) === 1) {
            return $serialized[0];
        }

        return $serialized;
    }

    #[Override]
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        if ($value instanceof LinkCollection) {
            return $value->isEmpty();
        }

        return parent::isValueEmpty($value, $element);
    }

    // Element lifecycle
    // -------------------------------------------------------------------------

    #[Override]
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        $value = $element->getFieldValue((string) $this->handle);

        if (! $value instanceof LinkCollection || ! $this->id || ! $element->id) {
            parent::afterElementSave($element, $isNew);

            return;
        }

        $siteId = (int) $element->siteId;
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
        if ($relations !== []) {
            $existing = DB::table(Table::ELEMENTS)
                ->whereIn('id', array_column($relations, 'targetId'))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $relations = array_values(array_filter($relations, fn ($r) => in_array((int) $r['targetId'], $existing, true)));
        }

        app(Relations::class)->saveRelations((int) $this->id, (int) $element->id, $siteId, $relations);

        // Keep the link index (Links screen, reverse lookups, checks) in step with live content.
        try {
            app(LinkIndex::class)->indexValue($this, $element, $value);
        } catch (Throwable $e) {
            // The index is derived data; never fail a save over it.
            Log::warning("FreeLink couldn't index field \"$this->handle\" on element $element->id: {$e->getMessage()}");
        }

        parent::afterElementSave($element, $isNew);
    }

    #[Override]
    public function afterElementDelete(ElementInterface $element): void
    {
        // Relations are cleaned up by the CASCADE foreign key on ownerId (on a hard delete); this
        // covers the rest.
        if ($this->id && $element->id) {
            app(Relations::class)->deleteRelations((int) $this->id, (int) $element->id, (int) $element->siteId);
        }

        parent::afterElementDelete($element);
    }

    // Input
    // -------------------------------------------------------------------------

    /**
     * The editor: a table of Craft's own link controls. See LinkControl for why.
     */
    #[Override]
    public function uiControl(FieldContext $context): Control
    {
        $value = $context->value instanceof LinkCollection
            ? $context->value
            : $this->normalizeValue($context->value, $context->element);

        return new LinkControl($this)->control($context->path, $value, $context->element);
    }

    #[Override]
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (! $value instanceof LinkCollection) {
            return '';
        }

        $html = [];

        foreach ($value->getAll() as $link) {
            $url = $link->getUrl();

            if ($url !== null) {
                $html[] = Html::a(Html::encode($link->getText() ?? $url), $url, ['target' => '_blank', 'rel' => 'noopener noreferrer']);
            }
        }

        return implode(', ', $html);
    }

    #[Override]
    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return Html::a('craftcms.com', 'https://craftcms.com/');
    }

    // Validation
    // -------------------------------------------------------------------------

    /**
     * FreeLink's full checks run on every save except Craft's "essentials" scenario (drafts and
     * autosaves), which skips field validation by design; a draft gets them when it is published.
     * The security check runs on every save, drafts included: a new or changed element link must
     * point at an element that exists and the user (or GraphQL schema) can view, or a draft's editor
     * and preview would reveal the title and URL of an element its author can't see.
     *
     * @return list<Closure>
     */
    #[Override]
    public function getElementRules(ElementInterface $element): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) use ($element): void {
                $securityOnly = $element instanceof Element && $element->ruleset->getScenario() === ElementRules::SCENARIO_ESSENTIALS;

                foreach ($this->linkErrors($element, $value instanceof LinkCollection ? $value : null, $securityOnly) as $error) {
                    $fail($error);
                }
            },
        ];
    }

    /**
     * Every validation error for the field's value on an element.
     *
     * With `$securityOnly` (draft saves), only new or changed element links are checked, and only
     * for whether the target exists and the actor may view it.
     *
     * @return list<string>
     */
    public function linkErrors(ElementInterface $element, ?LinkCollection $value = null, bool $securityOnly = false): array
    {
        $value ??= $element->getFieldValue((string) $this->handle);

        if (! $value instanceof LinkCollection) {
            return [];
        }

        $errors = [];
        $name = $this->name ?? (string) $this->handle;

        if ($securityOnly) {
            $stored = $this->storedLinks($element);

            foreach ($value->getAll() as $index => $link) {
                if (! $link instanceof ElementLink || ! $link->targetId || isset($stored[self::linkKey($link)])) {
                    continue;
                }

                if (! $this->linkTargetExists($link) || ! $this->actorMayLinkTo($link, $element)) {
                    $errors[] = t('{attribute}: you can’t link to the {type} chosen for link {num}.', [
                        'attribute' => $name,
                        'type' => mb_strtolower($link::displayName()),
                        'num' => $index + 1,
                    ], category: 'freelink');
                }
            }

            return $errors;
        }

        // Multi-link count validation
        if ($this->multipleLinks) {
            $count = $value->count();

            if ($this->minLinks && $count < $this->minLinks) {
                $errors[] = t('{attribute} must have at least {min} links.', ['attribute' => $name, 'min' => $this->minLinks], category: 'freelink');
            }

            if ($this->maxLinks && $count > $this->maxLinks) {
                $errors[] = t('{attribute} must have no more than {max} links.', ['attribute' => $name, 'max' => $this->maxLinks], category: 'freelink');
            }
        }

        $enabledTypes = $this->getEnabledTypeHandles();
        // Links already stored on this element's live content are grandfathered: the 5.3 checks
        // (enabled type, target visibility, selection condition, icon) apply only to new or changed
        // links, so tightening a field never blocks saving existing content.
        $stored = $this->storedLinks($element);
        $deletedTargetPositions = $this->deletedTargetPositions($element);

        foreach ($value->getAll() as $index => $link) {
            $num = $index + 1;
            $storedLink = $stored[self::linkKey($link)] ?? null;
            $unchanged = $storedLink !== null;

            // The CP only offers enabled types, but a POST, GraphQL or MCP can name any type.
            if (! $unchanged && $enabledTypes && ! in_array($link->type, $enabledTypes, true)) {
                $errors[] = t('{attribute}: link {num} uses the “{type}” link type, which this field doesn’t allow.', [
                    'attribute' => $name,
                    'num' => $num,
                    'type' => $link->type,
                ], category: 'freelink');

                continue;
            }

            if ($link->icon !== null) {
                if (! $this->allowIcons) {
                    $link->icon = null;
                } elseif (! ($unchanged && $storedLink->icon === $link->icon) && ! Icons::exists($link->icon, $this->includeProIcons)) {
                    $errors[] = t('{attribute}: the icon for link {num} isn’t available.', ['attribute' => $name, 'num' => $num], category: 'freelink');
                }
            }

            if ($link instanceof ElementLink && $link->targetId && ! $this->linkTargetExists($link)) {
                if (in_array($index, $deletedTargetPositions, true)) {
                    // The stored target was deleted since this value was loaded: the link becomes
                    // empty, as it does on the next load anyway.
                    $link->targetId = null;
                    $link->targetSiteId = null;

                    continue;
                }

                $errors[] = t('{attribute}: the {type} chosen for link {num} doesn’t exist.', [
                    'attribute' => $name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $num,
                ], category: 'freelink');

                continue;
            }

            if (! $unchanged && $link instanceof ElementLink && $link->targetId && ! $this->actorMayLinkTo($link, $element)) {
                $errors[] = t('{attribute}: you can’t link to the {type} chosen for link {num}.', [
                    'attribute' => $name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $num,
                ], category: 'freelink');

                continue;
            }

            if (! $link->validate()) {
                foreach ($link->errors()->all() as $error) {
                    $errors[] = $error;
                }
            }

            if (! $unchanged && $link instanceof ElementLink && ! $this->linkMatchesSelectionCondition($link, $element)) {
                $errors[] = t('{attribute}: the {type} chosen for link {num} isn’t allowed here.', [
                    'attribute' => $name,
                    'type' => mb_strtolower($link::displayName()),
                    'num' => $num,
                ], category: 'freelink');
            }
        }

        return $errors;
    }

    /**
     * What makes two links "the same link" for grandfathering: type and destination. Label, new
     * window, icon and the advanced attributes can change without the link counting as new.
     */
    public static function linkKey(Link $link): string
    {
        if ($link instanceof ElementLink) {
            return $link->type.'|#'.(int) $link->targetId.'|'.(int) $link->targetSiteId;
        }

        $key = $link->type.'|'.$link->value;

        if ($link instanceof Site) {
            $key .= '|'.(int) $link->siteId;
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

        if (! $canonicalId || ! $this->handle) {
            return [];
        }

        try {
            $stored = $element::find()
                ->id($canonicalId)
                ->siteId($element->siteId)
                ->status(null)
                ->drafts(false)
                ->provisionalDrafts(false)
                ->revisions(false)
                ->one();
        } catch (Throwable) {
            return [];
        }

        if (! $stored) {
            return [];
        }

        try {
            $value = $stored->getFieldValue($this->handle);
        } catch (Throwable) {
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
        if (! $element->id || ! $this->id) {
            return [];
        }

        return DB::table(Tables::LINKS)
            ->where('fieldId', $this->id)
            ->whereIn('ownerId', array_values(array_unique(array_filter([$element->id, $element->getCanonicalId()]))))
            ->where('ownerSiteId', $element->siteId)
            ->whereNull('targetId')
            ->pluck('sortOrder')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Whether an element link's target exists and is the link type's element type, in any status.
     */
    public function linkTargetExists(ElementLink $link): bool
    {
        if (! $link->targetId) {
            return true;
        }

        $elementType = $link::elementType();

        if (! class_exists($elementType) || ! is_subclass_of($elementType, ElementInterface::class)) {
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
     * linkErrors() only calls this for new or changed links (see storedLinks()), so an editor can
     * still save live content that links to something someone else chose. A signed-in user (CP,
     * MCP) must be able to view the target; a GraphQL schema must be able to query it. Otherwise a
     * token or agent could link to an element it can't see and read its URL and title back.
     */
    public function actorMayLinkTo(ElementLink $link, ?ElementInterface $owner = null): bool
    {
        if (! $link->targetId) {
            return true;
        }

        // The schema must be able to read the target on the site the link points at.
        $siteId = $link->targetSiteId ?? ($owner?->siteId ? (int) $owner->siteId : $link->ownerSiteId);

        if (GqlScope::isActive() && ! GqlScope::canQuery($link->targetId, $siteId)) {
            return false;
        }

        try {
            $user = currentUser();
        } catch (Throwable) {
            $user = null;
        }

        if (! $user) {
            return true;
        }

        $target = $link->getElement();

        if (! $target) {
            /** @var class-string<ElementInterface> $elementType */
            $elementType = $link::elementType();
            $target = $elementType::find()->id($link->targetId)->site('*')->unique()->status(null)->one();
        }

        return $target !== null && Gate::forUser($user)->allows('view', $target);
    }

    /**
     * Whether an element link's target matches its type's selection condition.
     *
     * The modal only narrows what an editor is offered. A POST can name any element ID, so the
     * condition is checked again here against the element itself.
     */
    public function linkMatchesSelectionCondition(ElementLink $link, ?ElementInterface $owner = null): bool
    {
        if (! $link->targetId) {
            return true;
        }

        $condition = $this->getSelectionCondition($link->type ?: $link::handle());

        if (! $condition) {
            return true;
        }

        $target = $link->getElement();

        if (! $target && ! $link->targetSiteId && $owner?->siteId) {
            // No site recorded and nothing in the current one: look in the owner's site.
            /** @var class-string<ElementInterface> $elementType */
            $elementType = $link::elementType();
            $target = $elementType::find()->id($link->targetId)->siteId($owner->siteId)->status(null)->one();
        }

        if (! $target) {
            // A target that doesn't exist can't match. Saving would only store a dead link.
            return false;
        }

        if ($condition instanceof ElementCondition) {
            $condition->referenceElement = $owner;
        }

        return $condition->matchElement($target);
    }

    // GraphQL
    // -------------------------------------------------------------------------

    /**
     * Always a list of `FreeLinkInterface`, as in FreeLink 5.
     *
     * @return Type|array<string, mixed>
     */
    #[Override]
    public function getContentGqlType(): Type|array
    {
        return LinkTypeGenerator::generateType($this);
    }

    /**
     * `FreeLinkInput` (single-link mode) or `[FreeLinkInput]` (multi-link mode). Values go through
     * normalizeValue() and the same validation as a CP save.
     *
     * @return Type|array<string, mixed>
     */
    #[Override]
    public function getContentGqlMutationArgumentType(): Type|array
    {
        $type = LinkInput::getType();

        return [
            'name' => (string) $this->handle,
            'type' => $this->multipleLinks ? Type::listOf($type) : $type,
            'description' => $this->instructions,
        ];
    }

    // MCP
    // -------------------------------------------------------------------------

    /**
     * JSON schema of the values this field accepts (core MCP element tools). Built from the field's
     * settings: only enabled link types, only the inputs the field shows.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        $links = app(Links::class);
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
            'targetId' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Element ID for '.($elementTypes ? implode('/', $elementTypes) : 'element').' links. Omit to keep the target already at this position; null clears it.'],
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
            $properties['icon'] = $nullableString + ['description' => 'Icon name from Craft’s icon set'.($this->includeProIcons ? '' : ' (free Font Awesome icons only)').'.'];
        }

        $link = [
            'type' => 'object',
            'required' => ['type'],
            'additionalProperties' => false,
            'properties' => $properties,
        ];

        $description = 'FreeLink. Enabled link types: '.implode(', ', $enabled).'.';

        if (! $this->multipleLinks) {
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
    public function getMcpValue(mixed $value, ElementInterface $element, McpOutputContext $context): mixed
    {
        if (! $value instanceof LinkCollection) {
            return $this->multipleLinks ? [] : null;
        }

        $out = [];

        foreach ($value->getAll() as $link) {
            $data = [
                'type' => $link->type,
                'url' => $link->getUrl(),
                'text' => $link->getText(),
            ];

            if ($link instanceof ElementLink) {
                $target = $link->getElement();

                if ($target && ! $context->public && ! $link->isTargetVisible()) {
                    // A stored link (e.g. in a draft) to an element this user can't view: no title,
                    // no URL, no element.
                    $data['url'] = null;
                    $data['text'] = $link->label;
                    $target = null;
                }

                if ($context->public) {
                    // Public server: no element, and nothing for a target that isn't live.
                    if (! $target || ! in_array($target->getStatus(), [Element::STATUS_ENABLED, 'live'], true)) {
                        continue;
                    }
                    $out[] = $data;

                    continue;
                }

                $data['targetId'] = $link->targetId;
                $data['targetSiteId'] = $link->targetSiteId;
                $data['target'] = $target;
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

    // Search
    // -------------------------------------------------------------------------

    #[Override]
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (! $value instanceof LinkCollection) {
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
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Fields;

use CraftCms\Cms\Element\Conditions\ElementCondition;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Link as LinkUiControl;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Textarea;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Base\Link;
use JustinHoltWeb\FreeLink\Links\Custom;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Phone;
use JustinHoltWeb\FreeLink\Links\Site;
use JustinHoltWeb\FreeLink\Links\Sms;
use JustinHoltWeb\FreeLink\Links\Url;
use JustinHoltWeb\FreeLink\Models\LinkCollection;
use JustinHoltWeb\FreeLink\Services\Links;
use Throwable;

use function CraftCms\Cms\t;

/**
 * The field's editor, built from Craft 6's own Ui controls.
 *
 * Every link is a row of a core `Table` control: a core `Link` control (Craft's link editor, with
 * FreeLink's enabled types as its link types), plus an `IconPicker` column when the field allows
 * icons and a custom-attributes column when it shows the advanced settings. Multi-link fields can add,
 * delete and reorder rows; single-link fields have exactly one.
 *
 * The control speaks Craft's link value shape (`{type, value, label, target, class, …}`, element
 * values as `{entry:12@1:url}` reference tags, mailto:/tel:/sms: prefixes). This class converts
 * between that and FreeLink's own JSON in both directions. Rows posted by the control are told apart
 * from stored JSON and API input by their `link` key — stored links have `type` at the top level.
 */
final class LinkControl
{
    /** Prefixes the core Link control expects on text link values, per FreeLink type. */
    private const array PREFIXES = [
        'email' => 'mailto:',
        'phone' => 'tel:',
        'sms' => 'sms:',
    ];

    public function __construct(private readonly FreeLinkField $field) {}

    /**
     * @param  string|list<string>  $path
     */
    public function control(string|array $path, mixed $value, ?ElementInterface $element): Table
    {
        $columns = [
            'link' => [
                'heading' => t('Link', category: 'freelink'),
                'control' => LinkUiControl::make('link')
                    ->types($this->pickerTypes($element))
                    ->showLabelField($this->field->showLabel)
                    ->advancedFields($this->advancedFields()),
            ],
        ];

        if ($this->field->allowIcons) {
            $columns['icon'] = [
                'heading' => t('Icon', category: 'freelink'),
                'control' => IconPicker::make('icon')->freeOnly(! $this->field->includeProIcons),
            ];
        }

        if ($this->field->showAdvanced) {
            $columns['attributes'] = [
                'heading' => t('Custom attributes', category: 'freelink'),
                'control' => Textarea::make('attributes')->rows(2)->monospace()->placeholder('data-track=cta'),
            ];
        }

        $table = Table::make($path)
            ->columns($columns)
            ->value($this->rows($value));

        if ($this->field->multipleLinks) {
            $table->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add a link', category: 'freelink'))
                ->minRows($this->field->minLinks ?: null)
                ->maxRows($this->field->maxLinks ?: null);
        } else {
            $table->minRows(1)->maxRows(1);
        }

        return $table;
    }

    /**
     * The table rows for a field value. An empty field gets one row of the default type.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(mixed $value): array
    {
        $links = $value instanceof LinkCollection ? $value->getAll() : [];
        $rows = [];

        foreach ($links as $link) {
            $rows[] = $this->row($link);
        }

        if ($rows === []) {
            $rows[] = [
                'link' => [
                    'type' => $this->controlType($this->field->defaultLinkType, null),
                    'value' => '',
                    'target' => $this->field->defaultNewWindow ? '_blank' : null,
                ],
                'icon' => null,
                'attributes' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Link $link): array
    {
        $control = [
            'type' => $this->controlType($link->type, $link instanceof Site ? $link->siteId : null),
            'value' => $this->controlValue($link),
            'label' => $link->label,
            'urlSuffix' => $link->urlSuffix,
            'target' => $link->newWindow ? '_blank' : null,
            'title' => $link->title,
            'class' => $link->classes,
            'id' => $link->id,
            'rel' => $link->rel,
            'ariaLabel' => $link->ariaLabel,
            'defaultLabel' => $this->defaultLabel($link),
        ];

        return [
            'link' => array_filter($control, fn ($v) => $v !== null),
            'icon' => $link->icon,
            'attributes' => self::attributesToText($link->customAttributes),
        ];
    }

    /**
     * Whether a posted value is the control's shape: a list of rows with a `link` array.
     */
    public static function isControlValue(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || isset($value['type'])) {
            return false;
        }

        foreach ($value as $row) {
            if (! is_array($row) || ! array_key_exists('link', $row) || isset($row['type'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Converts posted control rows to FreeLink link data (the shape Links::createLink() reads).
     *
     * Element links always carry `targetId` (null when nothing is selected), so the posted choice
     * wins over the relations table, as a CP save always has.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    public static function fromControlRows(array $rows, Links $links): array
    {
        $data = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['link'] ?? null)) {
                continue;
            }

            $control = $row['link'];
            $type = is_string($control['type'] ?? null) ? $control['type'] : '';
            $siteId = null;

            if (preg_match('/^site:(\d+)$/', $type, $m)) {
                $type = 'site';
                $siteId = (int) $m[1];
            }

            $class = $links->getTypeByHandle($type);

            if ($class === null) {
                continue;
            }

            $raw = is_scalar($control['value'] ?? null) ? trim((string) $control['value']) : '';

            $link = [
                'type' => $type,
                'label' => $control['label'] ?? null,
                'newWindow' => ($control['target'] ?? null) === '_blank',
                'urlSuffix' => $control['urlSuffix'] ?? null,
                'title' => $control['title'] ?? null,
                'classes' => $control['class'] ?? null,
                'id' => $control['id'] ?? null,
                'rel' => $control['rel'] ?? null,
                'ariaLabel' => $control['ariaLabel'] ?? null,
                'icon' => $row['icon'] ?? null,
                'customAttributes' => self::textToAttributes(is_string($row['attributes'] ?? null) ? $row['attributes'] : ''),
            ];

            if (is_subclass_of($class, ElementLink::class)) {
                [$targetId, $targetSiteId] = self::parseReference($raw, $class);
                $link['targetId'] = $targetId;
                $link['targetSiteId'] = $targetSiteId;
            } elseif ($class === Email::class || is_subclass_of($class, Email::class)) {
                $address = self::stripPrefix($raw, 'mailto:');
                $subject = null;

                if (preg_match('/^([^?]*)\?(.*)$/s', $address, $m)) {
                    $address = $m[1];
                    parse_str($m[2], $query);
                    $subject = is_string($query['subject'] ?? null) ? $query['subject'] : null;
                }

                $link['value'] = rawurldecode($address);
                $link['subject'] = $subject;
            } else {
                $link['value'] = isset(self::PREFIXES[$type]) ? self::stripPrefix($raw, self::PREFIXES[$type]) : $raw;
                $link['siteId'] = $siteId;
            }

            $data[] = $link;
        }

        return $data;
    }

    /**
     * The link types the core Link control offers: the field's enabled types, each in the shape
     * `Ui\Controls\Link::types()` takes. Site links get one type per site when there are several.
     *
     * @return list<array<string, mixed>>
     */
    public function pickerTypes(?ElementInterface $element): array
    {
        $links = app(Links::class);
        $types = [];

        foreach ($this->field->getEnabledTypeHandles() ?: ['url'] as $handle) {
            $class = $links->getTypeByHandle($handle);

            if ($class === null || (method_exists($class, 'isAvailable') && ! $class::isAvailable())) {
                continue;
            }

            $label = ($this->field->linkTypes[$handle]['label'] ?? '') ?: $class::displayName();

            if (is_subclass_of($class, ElementLink::class)) {
                $types[] = $this->elementType($handle, $label, $class, $element);

                continue;
            }

            if ($class === Site::class) {
                foreach ($this->siteTypes($label) as $siteType) {
                    $types[] = $siteType;
                }

                continue;
            }

            $types[] = [
                'id' => $handle,
                'label' => $label,
                'kind' => 'text',
                'prefixes' => isset(self::PREFIXES[$handle]) ? [self::PREFIXES[$handle]] : ($class === Url::class ? ['https://', 'http://'] : []),
                'pattern' => $this->pattern($class),
                'inputAttributes' => array_filter(['placeholder' => $class::inputPlaceholder()]),
            ];
        }

        return $types;
    }

    /**
     * @param  class-string<ElementLink>  $class
     * @return array<string, mixed>
     */
    private function elementType(string $handle, string $label, string $class, ?ElementInterface $element): array
    {
        /** @var class-string<ElementInterface> $elementType */
        $elementType = $class::elementType();
        $condition = $this->field->getSelectionCondition($handle);

        if ($condition instanceof ElementCondition) {
            $condition->referenceElement = $element;
        }

        $sources = $this->field->linkTypes[$handle]['sources'] ?? '*';

        return [
            'id' => $handle,
            'label' => $label,
            'kind' => 'element',
            'elementType' => $elementType,
            'refHandle' => $elementType::refHandle(),
            'elementSelectConfig' => [
                'elementType' => $elementType,
                'limit' => 1,
                'single' => true,
                'sources' => FreeLinkField::normalizeSources($sources),
                'criteria' => [],
                'condition' => $condition?->getConfig(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siteTypes(string $label): array
    {
        $base = [
            'kind' => 'text',
            'prefixes' => [],
            'pattern' => '^[^:]*$',
            'inputAttributes' => ['placeholder' => Site::inputPlaceholder()],
        ];

        try {
            $sites = Sites::getAllSites()->all();
        } catch (Throwable) {
            $sites = [];
        }

        if (count($sites) < 2) {
            return [['id' => 'site', 'label' => $label] + $base];
        }

        $types = [];
        foreach ($sites as $site) {
            $types[] = ['id' => 'site:'.$site->id, 'label' => "$label: ".$site->getName()] + $base;
        }

        return $types;
    }

    /**
     * @param  class-string<Link>  $class
     */
    private function pattern(string $class): string
    {
        return match (true) {
            $class === Url::class => '^(https?:\/\/)?[^\s]+$',
            $class === Email::class => '^mailto:[^@\s]+@[^@\s]+$',
            $class === Phone::class => '^tel:[\d\s\-\+\(\)\.x]+$',
            $class === Sms::class => '^sms:[\d\s\-\+\(\)\.x]+$',
            $class === Custom::class => '.*',
            default => '.*',
        };
    }

    /**
     * @return list<string>
     */
    private function advancedFields(): array
    {
        $fields = [];

        if ($this->field->showNewWindow) {
            $fields[] = 'target';
        }

        if ($this->field->showAdvanced) {
            array_push($fields, 'urlSuffix', 'title', 'class', 'id', 'rel', 'ariaLabel');
        }

        return $fields;
    }

    private function controlType(string $handle, ?int $siteId): string
    {
        if ($handle !== 'site') {
            return $handle;
        }

        try {
            $multiSite = Sites::getAllSites()->count() > 1;
        } catch (Throwable) {
            $multiSite = false;
        }

        if (! $multiSite) {
            return 'site';
        }

        return 'site:'.($siteId ?: Sites::getPrimarySite()->id);
    }

    private function controlValue(Link $link): string
    {
        if ($link instanceof ElementLink) {
            if (! $link->targetId) {
                return '';
            }

            /** @var class-string<ElementInterface> $elementType */
            $elementType = $link::elementType();
            $refHandle = class_exists($elementType) ? $elementType::refHandle() : $link->type;

            return sprintf('{%s:%d%s:url}', $refHandle, $link->targetId, $link->targetSiteId ? '@'.$link->targetSiteId : '');
        }

        $value = (string) $link->value;

        if ($value === '') {
            return '';
        }

        if ($link instanceof Email) {
            return 'mailto:'.$value.($link->subject ? '?subject='.rawurlencode($link->subject) : '');
        }

        return isset(self::PREFIXES[$link->type]) ? self::PREFIXES[$link->type].$value : $value;
    }

    private function defaultLabel(Link $link): ?string
    {
        try {
            if ($link instanceof ElementLink) {
                // Never show the title of an element the editor can't view (a link someone else saved).
                return $link->getElement() && $link->isTargetVisible() ? (string) $link->getElement() : null;
            }

            return $link->getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Element ID and site from a `{refHandle:id@site:url}` reference tag, or a bare ID.
     *
     * @param  class-string<ElementLink>  $class
     * @return array{0: int|null, 1: int|null}
     */
    public static function parseReference(string $value, string $class): array
    {
        if ($value === '') {
            return [null, null];
        }

        if (ctype_digit($value)) {
            return [(int) $value, null];
        }

        if (preg_match('/^\{[\w\\\\]+:(\d+)(?:@(\d+))?(?::[\w.]+)?\}$/', $value, $m)) {
            return [(int) $m[1], ! empty($m[2]) ? (int) $m[2] : null];
        }

        return [null, null];
    }

    private static function stripPrefix(string $value, string $prefix): string
    {
        return stripos($value, $prefix) === 0 ? substr($value, strlen($prefix)) : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    public static function attributesToText(array $attributes): ?string
    {
        $lines = [];

        foreach ($attributes as $attr) {
            $name = $attr['attribute'] ?? '';

            if (is_string($name) && $name !== '') {
                $lines[] = $name.'='.(is_scalar($attr['value'] ?? null) ? (string) $attr['value'] : '');
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * `name=value` lines (or a bare `name`) to FreeLink's custom attribute list.
     *
     * @return list<array{attribute: string, value: string}>
     */
    public static function textToAttributes(string $text): array
    {
        $attributes = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $attributes[] = ['attribute' => trim($name), 'value' => $value];
        }

        return $attributes;
    }
}

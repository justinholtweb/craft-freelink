<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Base;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Html;
use Illuminate\Support\HtmlString;
use JsonSerializable;
use JustinHoltWeb\FreeLink\Helpers\Icons;
use JustinHoltWeb\FreeLink\Helpers\UrlSafety;
use JustinHoltWeb\FreeLink\Models\Model;
use Override;
use Stringable;

use function CraftCms\Cms\t;

/**
 * Base link model. All link types extend this class.
 *
 * A plain component (not an element): links have no table, cache or element query of their own.
 */
class Link extends Model implements JsonSerializable, Stringable
{
    public string $type = '';

    public ?string $value = null;

    public ?string $label = null;

    public bool $newWindow = false;

    public ?string $ariaLabel = null;

    public ?string $title = null;

    public ?string $urlSuffix = null;

    public ?string $classes = null;

    public ?string $id = null;

    public ?string $rel = null;

    /** @var list<array<string, mixed>> */
    public array $customAttributes = [];

    /** Icon name from Craft's icon set (e.g. `arrow-right`), when the field allows icons. */
    public ?string $icon = null;

    /**
     * Returns the display name for this link type.
     */
    #[Override]
    public static function displayName(): string
    {
        return 'Link';
    }

    /**
     * Returns the handle for this link type.
     */
    public static function handle(): string
    {
        return '';
    }

    /**
     * Whether this link type links to a Craft element.
     */
    public function isElement(): bool
    {
        return false;
    }

    /**
     * Returns the resolved URL for this link.
     */
    public function getUrl(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $url = $this->getRawUrl();

        // A script-running URL never leaves the model, whatever wrote it. See UrlSafety.
        return UrlSafety::isUnsafeUrl($url) ? null : $url;
    }

    /**
     * The URL as stored, suffix applied, before the safety check in getUrl().
     */
    protected function getRawUrl(): ?string
    {
        $url = $this->getBaseUrl();

        if ($url !== null && $this->urlSuffix) {
            $url .= $this->urlSuffix;
        }

        return $url;
    }

    #[Override]
    protected function checks(?array $attributes): void
    {
        if (self::checking($attributes, 'value') && ! $this->isElement() && (string) $this->value !== '' && UrlSafety::isUnsafeUrl($this->getRawUrl())) {
            // Element links resolve their URL from the element, which an editor doesn't type.
            $this->addError('value', t('Links can’t use javascript:, vbscript: or data: URLs.', category: 'freelink'));
        }

        if (self::checking($attributes, 'customAttributes')) {
            foreach ($this->customAttributes as $attr) {
                $name = $attr['attribute'] ?? '';

                if ($name !== '' && ! UrlSafety::isSafeAttributeName($name)) {
                    $this->addError('customAttributes', t('“{name}” can’t be used as a custom attribute.', [
                        'name' => is_string($name) ? $name : '',
                    ], category: 'freelink'));
                }
            }
        }

        if (self::checking($attributes, 'icon') && $this->icon !== null && $this->icon !== '') {
            if (! Icons::isValidName($this->icon) || Icons::path($this->icon) === null) {
                $this->addError('icon', t('“{icon}” isn’t an icon.', ['icon' => $this->icon], category: 'freelink'));
            }
        }
    }

    /**
     * The link's icon as inline SVG markup, or null when it has none.
     *
     * Usage: `{{ link.iconSvg }}` or `{{ link.getIconSvg({class: 'icon'}) }}`
     *
     * @param  array<string, mixed>  $attributes
     */
    public function getIconSvg(array $attributes = []): ?HtmlString
    {
        $svg = Icons::svg($this->icon, $attributes);

        return $svg === null ? null : new HtmlString($svg);
    }

    /**
     * Custom attributes that are safe to render; anything else is dropped silently.
     *
     * @return array<string, string>
     */
    public function getSafeCustomAttributes(): array
    {
        $safe = [];

        foreach ($this->customAttributes as $attr) {
            $name = $attr['attribute'] ?? '';

            if ($name !== '' && UrlSafety::isSafeAttributeName($name)) {
                $safe[$name] = (string) ($attr['value'] ?? '');
            }
        }

        return $safe;
    }

    /**
     * Returns the base URL before suffix is applied.
     * Override in subclasses for URL prefix behavior (mailto:, tel:, etc.).
     */
    protected function getBaseUrl(): ?string
    {
        return $this->value;
    }

    /**
     * Returns the display text for this link.
     */
    public function getText(): ?string
    {
        if ($this->label) {
            return $this->label;
        }

        return $this->getUrl();
    }

    /**
     * Returns the linked element, if any.
     */
    public function getElement(): ?ElementInterface
    {
        return null;
    }

    /**
     * Returns the target attribute value.
     */
    public function getTarget(): ?string
    {
        return $this->newWindow ? '_blank' : null;
    }

    /**
     * Returns a full `<a>` tag for this link.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function getLink(array $attributes = []): ?HtmlString
    {
        $url = $this->getUrl();

        if ($url === null) {
            return null;
        }

        $defaultAttrs = [
            'href' => $url,
        ];

        if ($this->newWindow) {
            $defaultAttrs['target'] = '_blank';
            // Merge noopener noreferrer with any existing rel
            $relParts = $this->rel ? explode(' ', $this->rel) : [];
            if (! in_array('noopener', $relParts, true)) {
                $relParts[] = 'noopener';
            }
            if (! in_array('noreferrer', $relParts, true)) {
                $relParts[] = 'noreferrer';
            }
            $defaultAttrs['rel'] = implode(' ', $relParts);
        } elseif ($this->rel) {
            $defaultAttrs['rel'] = $this->rel;
        }

        if ($this->ariaLabel) {
            $defaultAttrs['aria-label'] = $this->ariaLabel;
        }

        if ($this->title) {
            $defaultAttrs['title'] = $this->title;
        }

        if ($this->classes) {
            $defaultAttrs['class'] = $this->classes;
        }

        if ($this->id) {
            $defaultAttrs['id'] = $this->id;
        }

        // Custom attributes never replace the attributes above: an editor's `href`, `target` or
        // `rel` would otherwise undo the checks on them.
        $defaultAttrs += $this->getSafeCustomAttributes();

        // Passed attributes take precedence.
        $attrs = array_merge($defaultAttrs, $attributes);

        $text = Html::encode($this->getText() ?? $url);

        return new HtmlString(Html::tag('a', $text, $attrs));
    }

    /**
     * Whether this link has no meaningful value.
     */
    public function isEmpty(): bool
    {
        return empty($this->value);
    }

    /**
     * Placeholder text for the value input.
     */
    public static function inputPlaceholder(): string
    {
        return '';
    }

    /**
     * Serialize this link to an array for JSON storage.
     *
     * @param  string[]  $fields
     * @param  string[]  $expand
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(array $fields = [], array $expand = [], bool $recursive = true): array
    {
        $data = [
            'type' => $this->type,
            'value' => $this->value,
            'label' => $this->label,
            'newWindow' => $this->newWindow,
            'ariaLabel' => $this->ariaLabel,
            'title' => $this->title,
            'urlSuffix' => $this->urlSuffix,
            'classes' => $this->classes,
            'id' => $this->id,
            'rel' => $this->rel,
            'customAttributes' => $this->customAttributes,
        ];

        // Only stored when set, so links without an icon keep the pre-5.3 JSON shape.
        if ($this->icon !== null && $this->icon !== '') {
            $data['icon'] = $this->icon;
        }

        return $data;
    }

    /**
     * Returns resolved values for API/JSON output (Element API, json_encode).
     * Distinct from toArray() which returns raw storage data.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->getUrl(),
            'text' => $this->getText(),
            'target' => $this->getTarget(),
            'newWindow' => $this->newWindow,
            'label' => $this->label,
            'ariaLabel' => $this->ariaLabel,
            'title' => $this->title,
            'classes' => $this->classes,
            'id' => $this->id,
            'rel' => $this->rel,
            'icon' => $this->icon,
            'isEmpty' => $this->isEmpty(),
            'isElement' => $this->isElement(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toApiArray();
    }

    public function __toString(): string
    {
        return $this->getUrl() ?? '';
    }
}

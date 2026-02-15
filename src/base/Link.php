<?php

namespace justinholtweb\freelink\base;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use Twig\Markup;
use yii\base\Model;

/**
 * Base link model. All link types extend this class.
 * Extends yii\base\Model (NOT Craft's Element class) for lightweight operation.
 */
class Link extends Model
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
    public array $customAttributes = [];

    /**
     * Returns the display name for this link type.
     */
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

        $url = $this->getBaseUrl();

        if ($url !== null && $this->urlSuffix) {
            $url .= $this->urlSuffix;
        }

        return $url;
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
    public function getElement(): ?\craft\base\ElementInterface
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
     */
    public function getLink(array $attributes = []): ?Markup
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
            if (!in_array('noopener', $relParts)) {
                $relParts[] = 'noopener';
            }
            if (!in_array('noreferrer', $relParts)) {
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

        // Add custom attributes
        foreach ($this->customAttributes as $attr) {
            if (!empty($attr['attribute'])) {
                $defaultAttrs[$attr['attribute']] = $attr['value'] ?? '';
            }
        }

        // Merge with passed attributes (passed attrs take precedence)
        $attrs = array_merge($defaultAttrs, $attributes);

        $text = Html::encode($this->getText() ?? $url);

        return Template::raw(Html::tag('a', $text, $attrs));
    }

    /**
     * Whether this link has no meaningful value.
     */
    public function isEmpty(): bool
    {
        return empty($this->value);
    }

    /**
     * Returns the link type-specific settings schema for the field settings UI.
     */
    public static function settingsSchema(): array
    {
        return [];
    }

    /**
     * Returns the input HTML for this link type in the field.
     */
    public function getInputHtml(string $namePrefix, array $fieldSettings = []): string
    {
        return Craft::$app->getView()->renderTemplate('freelink/field/_inputs/text', [
            'name' => $namePrefix . '[value]',
            'value' => $this->value,
            'placeholder' => static::inputPlaceholder(),
        ]);
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
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
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
    }

    public function __toString(): string
    {
        return $this->getUrl() ?? '';
    }
}

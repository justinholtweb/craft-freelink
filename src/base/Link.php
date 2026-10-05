<?php

namespace justinholtweb\freelink\base;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use JsonSerializable;
use justinholtweb\freelink\helpers\UrlSafety;
use Twig\Markup;
use yii\base\Model;

/**
 * Base link model. All link types extend this class.
 * Extends yii\base\Model (NOT Craft's Element class) for lightweight operation.
 */
class Link extends Model implements JsonSerializable
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

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['value', 'validateSafeUrl', 'skipOnEmpty' => true];
        $rules[] = ['customAttributes', 'validateCustomAttributes', 'skipOnEmpty' => true];

        return $rules;
    }

    public function validateSafeUrl(string $attribute): void
    {
        // Element links resolve their URL from the element, which an editor doesn't type.
        if (!$this->isElement() && UrlSafety::isUnsafeUrl($this->getRawUrl())) {
            $this->addError($attribute, Craft::t('freelink', 'Links can’t use javascript:, vbscript: or data: URLs.'));
        }
    }

    public function validateCustomAttributes(string $attribute): void
    {
        foreach ($this->customAttributes as $attr) {
            $name = $attr['attribute'] ?? '';

            if ($name !== '' && !UrlSafety::isSafeAttributeName($name)) {
                $this->addError($attribute, Craft::t('freelink', '“{name}” can’t be used as a custom attribute.', [
                    'name' => is_string($name) ? $name : '',
                ]));
            }
        }
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
                $safe[$name] = (string)($attr['value'] ?? '');
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
     *
     * @param array<string, mixed> $attributes
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

        // Add custom attributes. They never replace the attributes above: an editor's `href`,
        // `target` or `rel` would otherwise undo the checks on them.
        $defaultAttrs += $this->getSafeCustomAttributes();

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
     *
     * @return array<string, mixed>
     */
    public static function settingsSchema(): array
    {
        return [];
    }

    /**
     * Returns the input HTML for this link type in the field.
     *
     * @param array<string, mixed> $fieldSettings
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
     *
     * @param string[] $fields
     * @param string[] $expand
     * @return array<string, mixed>
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

    // region API serialization

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
            'isEmpty' => $this->isEmpty(),
            'isElement' => $this->isElement(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toApiArray();
    }

    // endregion

    public function __toString(): string
    {
        return $this->getUrl() ?? '';
    }
}

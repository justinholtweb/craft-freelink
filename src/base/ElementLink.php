<?php

namespace justinholtweb\freelink\base;

use Craft;
use craft\base\ElementInterface;

/**
 * Element link model. Used for link types that reference a Craft element.
 */
class ElementLink extends Link
{
    public ?int $targetId = null;
    public ?int $targetSiteId = null;

    /**
     * Allowed element sources. '*' = all.
     */
    public string|array $sources = '*';

    private ?ElementInterface $_element = null;
    private bool $_elementLoaded = false;

    /**
     * The element class this link type targets.
     * Override in subclasses.
     */
    public static function elementType(): string
    {
        return ElementInterface::class;
    }

    public function isElement(): bool
    {
        return true;
    }

    public function isEmpty(): bool
    {
        return empty($this->targetId);
    }

    /**
     * Lazy-loads and returns the linked element.
     */
    public function getElement(): ?ElementInterface
    {
        if (!$this->_elementLoaded) {
            $this->_elementLoaded = true;

            if ($this->targetId) {
                $elementType = static::elementType();
                $query = $elementType::find()
                    ->id($this->targetId)
                    ->status(null);

                if ($this->targetSiteId) {
                    $query->siteId($this->targetSiteId);
                }

                $this->_element = $query->one();
            }
        }

        return $this->_element;
    }

    /**
     * Sets the element directly (used for eager loading).
     */
    public function setElement(?ElementInterface $element): void
    {
        $this->_element = $element;
        $this->_elementLoaded = true;

        if ($element) {
            $this->targetId = $element->id;
        }
    }

    protected function getBaseUrl(): ?string
    {
        return $this->getElement()?->getUrl();
    }

    public function getText(): ?string
    {
        if ($this->label) {
            return $this->label;
        }

        $element = $this->getElement();

        return $element ? (string)$element : null;
    }

    /**
     * Returns the input HTML for element selection.
     */
    public function getInputHtml(string $namePrefix, array $fieldSettings = []): string
    {
        $elementType = static::elementType();
        $sources = $this->sources;

        // Get the element type's sources for the element selector
        $criteria = [];

        $elements = [];
        if ($this->targetId) {
            $element = $this->getElement();
            if ($element) {
                $elements = [$element];
            }
        }

        return Craft::$app->getView()->renderTemplate('freelink/field/_inputs/element', [
            'namePrefix' => $namePrefix,
            'elementType' => $elementType,
            'sources' => $sources,
            'elements' => $elements,
            'targetId' => $this->targetId,
            'targetSiteId' => $this->targetSiteId,
            'criteria' => $criteria,
            'limit' => 1,
        ]);
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        // Element links store null value in JSON; actual reference is in relations table
        $data['value'] = null;

        return $data;
    }
}

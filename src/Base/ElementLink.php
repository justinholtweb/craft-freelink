<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Base;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\FreeLink\Gql\GqlScope;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;

/**
 * Element link model. Used for link types that reference a Craft element.
 */
class ElementLink extends Link
{
    public ?int $targetId = null;

    public ?int $targetSiteId = null;

    /**
     * Allowed element sources. '*' = all.
     *
     * @var string|string[]
     */
    public string|array $sources = '*';

    private ?ElementInterface $element = null;

    private bool $elementLoaded = false;

    /**
     * The element class this link type targets. Override in subclasses.
     *
     * @return class-string<ElementInterface>|string
     */
    public static function elementType(): string
    {
        return ElementInterface::class;
    }

    /**
     * Whether the link type's element type is installed (Commerce types aren't until Commerce 6 is).
     */
    public static function isAvailable(): bool
    {
        $type = static::elementType();

        return class_exists($type) && is_subclass_of($type, ElementInterface::class);
    }

    #[Override]
    public function isElement(): bool
    {
        return true;
    }

    #[Override]
    public function isEmpty(): bool
    {
        return empty($this->targetId);
    }

    /**
     * Lazy-loads and returns the linked element.
     */
    #[Override]
    public function getElement(): ?ElementInterface
    {
        if (! $this->elementLoaded) {
            $this->elementLoaded = true;

            if ($this->targetId && static::isAvailable()) {
                /** @var class-string<ElementInterface> $elementType */
                $elementType = static::elementType();

                try {
                    $query = $elementType::find()
                        ->id($this->targetId)
                        ->status(null);

                    if ($this->targetSiteId) {
                        $query->siteId($this->targetSiteId);
                    }

                    $this->element = $query->one();
                } catch (Throwable) {
                    $this->element = null;
                }
            }
        }

        return $this->element;
    }

    /**
     * Sets the element directly (used for eager loading).
     */
    public function setElement(?ElementInterface $element): void
    {
        $this->element = $element;
        $this->elementLoaded = true;

        if ($element) {
            $this->targetId = $element->id;
        }
    }

    /**
     * Whether whoever is asking may see the target: in a GraphQL request, the active schema must be
     * able to query it; with a signed-in user (CP, MCP), the user must be able to view it. Front-end
     * templates (no user, no schema) see it, as they always have.
     *
     * Used wherever a target's title or URL goes back to the CP or an API, so a link someone saved
     * to an element another user can't view doesn't reveal it.
     */
    public function isTargetVisible(): bool
    {
        if (! $this->targetId) {
            return true;
        }

        try {
            if (GqlScope::isActive()) {
                return GqlScope::canQuery($this->targetId);
            }

            $user = currentUser();
        } catch (Throwable) {
            return false;
        }

        if (! $user) {
            return true;
        }

        $target = $this->getElement();

        return $target !== null && Gate::forUser($user)->allows('view', $target);
    }

    #[Override]
    protected function getBaseUrl(): ?string
    {
        return $this->getElement()?->getUrl();
    }

    #[Override]
    public function getText(): ?string
    {
        if ($this->label) {
            return $this->label;
        }

        $element = $this->getElement();

        return $element ? (string) $element : null;
    }

    /**
     * @param  string[]  $fields
     * @param  string[]  $expand
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(array $fields = [], array $expand = [], bool $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        // Element links store a null value in JSON; the reference lives in the relations table.
        $data['value'] = null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toApiArray(): array
    {
        $data = parent::toApiArray();

        $element = $this->getElement();

        $data['elementId'] = $this->targetId;
        $data['elementSiteId'] = $this->targetSiteId;
        $data['elementType'] = static::elementType();
        $data['elementTitle'] = $element?->title;
        $data['elementUrl'] = $element?->getUrl();
        $data['elementStatus'] = $element?->getStatus();

        return $data;
    }
}

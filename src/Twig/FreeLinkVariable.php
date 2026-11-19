<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Twig;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use JustinHoltWeb\FreeLink\Models\LinkReference;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;
use JustinHoltWeb\FreeLink\Services\Relations;

/**
 * Template variable available as `craft.freelink` and the `freelink` global (Twig), `$freelink`
 * (Blade).
 */
class FreeLinkVariable
{
    /**
     * Returns elements that link to the given element via FreeLink fields.
     *
     * Reads `freelink_links`, which also holds rows for drafts and revisions; prefer `linksTo()`,
     * which covers live content only and says where each link is.
     *
     * Usage: `craft.freelink.getRelatedElements(entry, 'myLinkField')`
     *
     * @return ElementInterface[]
     */
    public function getRelatedElements(ElementInterface $target, ?string $fieldHandle = null): array
    {
        if (! $target->id) {
            return [];
        }

        $fieldId = null;
        if ($fieldHandle) {
            $fieldId = Fields::getFieldByHandle($fieldHandle)?->id;

            if ($fieldId === null) {
                return [];
            }
        }

        $elements = [];

        foreach (app(Relations::class)->getRelatedOwners((int) $target->id, $fieldId) as $row) {
            $element = Elements::getElementById($row['ownerId'], null, $row['ownerSiteId']);

            if ($element) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Links in live content that point at an element.
     *
     * Usage: `craft.freelink.linksTo(entry, {field: 'cta', type: 'entry', site: 1, limit: 20, offset: 0})`
     *
     * @param  array<string, mixed>  $options
     * @return LinkReference[]
     */
    public function linksTo(ElementInterface $target, array $options = []): array
    {
        return app(Links::class)->findLinksTo($target, $options);
    }

    /**
     * How many links in live content point at an element.
     */
    public function linkCountTo(ElementInterface $target): int
    {
        $id = $target->getCanonicalId() ?: $target->id;

        return $id ? app(LinkIndex::class)->countTo((int) $id) : 0;
    }
}

<?php

namespace justinholtweb\freelink\twig;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\freelink\models\LinkReference;
use justinholtweb\freelink\Plugin;

/**
 * Template variable available as `craft.freelink` (and the `freelink` global).
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
        $fieldId = null;

        if ($fieldHandle) {
            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
            if ($field) {
                $fieldId = $field->id;
            }
        }

        $rows = Plugin::getInstance()->relations->getRelatedOwners($target->id, $fieldId);

        if (empty($rows)) {
            return [];
        }

        $elements = [];
        foreach ($rows as $row) {
            $element = Craft::$app->getElements()->getElementById(
                (int)$row['ownerId'],
                null,
                (int)$row['ownerSiteId'],
            );

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
     * @param array<string, mixed> $options
     * @return LinkReference[]
     */
    public function linksTo(ElementInterface $target, array $options = []): array
    {
        return Plugin::getInstance()->links->findLinksTo($target, $options);
    }

    /**
     * How many links in live content point at an element.
     */
    public function linkCountTo(ElementInterface $target): int
    {
        return $target->id ? Plugin::getInstance()->linkIndex->countTo((int)$target->id) : 0;
    }
}

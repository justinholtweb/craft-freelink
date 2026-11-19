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
        $options['viewer'] = $this->cpViewer();

        return Plugin::getInstance()->links->findLinksTo($target, $options);
    }

    /**
     * How many links in live content point at an element.
     */
    public function linkCountTo(ElementInterface $target): int
    {
        return $target->id ? Plugin::getInstance()->linkIndex->countTo((int)$target->id, $this->cpViewer()) : 0;
    }

    /**
     * In the control panel, results are limited to sources the signed-in user may view. Front-end
     * templates are the site's own and are not filtered.
     */
    private function cpViewer(): ?\craft\elements\User
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof \craft\elements\User ? $user : null;
    }
}

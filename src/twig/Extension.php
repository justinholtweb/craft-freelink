<?php

namespace justinholtweb\freelink\twig;

use Craft;
use craft\base\ElementInterface;
use justinholtweb\freelink\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension providing the `craft.freelink` variable.
 */
class Extension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return [
            'freelink' => new FreeLinkVariable(),
        ];
    }
}

/**
 * Template variable class available as `craft.freelink`.
 */
class FreeLinkVariable
{
    /**
     * Returns elements that link to the given element via FreeLink fields.
     *
     * Usage: `craft.freelink.getRelatedElements(entry, 'myLinkField')`
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
}

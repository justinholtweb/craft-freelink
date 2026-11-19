<?php

namespace justinholtweb\freelink\gql;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\errors\GqlException;

/**
 * What the active GraphQL schema may read, decided by Craft's own element resolvers (which apply the
 * schema's section/volume/group scopes and, for authenticated schemas, the user's view rights).
 *
 * Element types without a known resolver are treated as unreadable (fail closed).
 */
class GqlScope
{
    /** @var array<string, string> element class => GraphQL resolver class */
    public const RESOLVERS = [
        \craft\elements\Entry::class => \craft\gql\resolvers\elements\Entry::class,
        \craft\elements\Asset::class => \craft\gql\resolvers\elements\Asset::class,
        \craft\elements\Category::class => \craft\gql\resolvers\elements\Category::class,
        \craft\elements\User::class => \craft\gql\resolvers\elements\User::class,
        \craft\elements\GlobalSet::class => \craft\gql\resolvers\elements\GlobalSet::class,
        \craft\elements\Tag::class => \craft\gql\resolvers\elements\Tag::class,
        \craft\elements\Address::class => \craft\gql\resolvers\elements\Address::class,
        'craft\commerce\elements\Product' => 'craft\commerce\gql\resolvers\elements\Product',
        'craft\commerce\elements\Variant' => 'craft\commerce\gql\resolvers\elements\Variant',
    ];

    /**
     * Whether a GraphQL schema is active (we're inside a GraphQL request).
     */
    public static function isActive(): bool
    {
        try {
            Craft::$app->getGql()->getActiveSchema();
            return true;
        } catch (GqlException) {
            return false;
        }
    }

    /**
     * The subset of element IDs the active schema may query.
     *
     * @param int[] $ids
     * @return int[]
     */
    public static function filterQueryable(array $ids, ?int $siteId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return [];
        }

        $types = (new Query())
            ->select(['type', 'id'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $ids, 'dateDeleted' => null])
            ->all();

        $byType = [];
        foreach ($types as $row) {
            $byType[(string)$row['type']][] = (int)$row['id'];
        }

        $allowed = [];

        foreach ($byType as $class => $classIds) {
            $resolver = self::RESOLVERS[$class] ?? null;

            if (!$resolver || !class_exists($resolver) || !method_exists($resolver, 'prepareQuery')) {
                continue;
            }

            // Any status: a schema can always ask for disabled/pending elements with `status: null`.
            $arguments = ['id' => $classIds, 'status' => null];
            if ($siteId) {
                $arguments['siteId'] = $siteId;
            }

            try {
                $query = $resolver::prepareQuery(null, $arguments);
            } catch (\Throwable) {
                continue;
            }

            if (!$query instanceof ElementQueryInterface) {
                continue;
            }

            foreach ($query->ids() as $id) {
                $allowed[] = (int)$id;
            }
        }

        return $allowed;
    }

    public static function canQuery(int $id, ?int $siteId = null): bool
    {
        return in_array($id, self::filterQueryable([$id], $siteId), true);
    }

    /**
     * Whether the schema may query an element we already hold.
     */
    public static function canQueryElement(ElementInterface $element): bool
    {
        return $element->id && self::canQuery((int)$element->id, $element->siteId ? (int)$element->siteId : null);
    }
}

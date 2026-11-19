<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql;

use CraftCms\Cms\Address\Elements\Address;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Resolvers\Elements\Address as AddressResolver;
use CraftCms\Cms\Gql\Resolvers\Elements\Asset as AssetResolver;
use CraftCms\Cms\Gql\Resolvers\Elements\Entry as EntryResolver;
use CraftCms\Cms\Gql\Resolvers\Elements\User as UserResolver;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the active GraphQL schema may read, decided by Craft's own element resolvers (which apply the
 * schema's section/volume/group scopes and, for authenticated schemas, the user's view rights).
 *
 * Element types without a known resolver are treated as unreadable (fail closed).
 */
final class GqlScope
{
    /**
     * Element class => GraphQL resolver class. Commerce 6's resolvers are added once it ships.
     *
     * @var array<string, string>
     */
    public const array RESOLVERS = [
        Entry::class => EntryResolver::class,
        Asset::class => AssetResolver::class,
        User::class => UserResolver::class,
        Address::class => AddressResolver::class,
    ];

    /**
     * Whether a GraphQL schema is active (we're inside a GraphQL request).
     */
    public static function isActive(): bool
    {
        try {
            app(Gql::class)->getActiveSchema();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The subset of element IDs the active schema may query.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public static function filterQueryable(array $ids, ?int $siteId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        // Craft's element resolvers don't apply the schema's site scopes (the query condition
        // builder does, later), so the site is checked here: a given site must be one the schema can
        // read, and without one only readable sites count.
        $allowedSiteIds = self::allowedSiteIds();

        if ($siteId !== null && ! in_array($siteId, $allowedSiteIds, true)) {
            return [];
        }

        if ($allowedSiteIds === []) {
            return [];
        }

        $rows = DB::table(Table::ELEMENTS)
            ->select(['type', 'id'])
            ->whereIn('id', $ids)
            ->whereNull('dateDeleted')
            ->get();

        $byType = [];
        foreach ($rows as $row) {
            $byType[(string) $row->type][] = (int) $row->id;
        }

        $allowed = [];

        foreach ($byType as $class => $classIds) {
            $resolver = self::RESOLVERS[$class] ?? null;

            if (! $resolver || ! class_exists($resolver) || ! method_exists($resolver, 'prepareQuery')) {
                continue;
            }

            // Any status: a schema can always ask for disabled/pending elements with `status: null`.
            $arguments = ['id' => $classIds, 'status' => null, 'siteId' => $siteId ?? $allowedSiteIds];

            if ($siteId === null) {
                $arguments['unique'] = true;
            }

            try {
                $query = $resolver::prepareQuery(null, $arguments);
            } catch (Throwable) {
                continue;
            }

            if (! $query instanceof ElementQueryInterface) {
                continue;
            }

            foreach ($query->ids() as $id) {
                $allowed[] = (int) $id;
            }
        }

        return $allowed;
    }

    /**
     * IDs of the sites the active schema may read.
     *
     * @return int[]
     */
    public static function allowedSiteIds(): array
    {
        try {
            return array_map(fn ($site) => (int) $site->id, GqlHelper::getAllowedSites());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Whether the active schema may query an element, on the given site (or, with none, on any site
     * it can read). Pass the link's target site, or the owner's site when the link names none.
     */
    public static function canQuery(int $id, ?int $siteId = null): bool
    {
        return in_array($id, self::filterQueryable([$id], $siteId), true);
    }

    /**
     * Whether the schema may query an element we already hold.
     */
    public static function canQueryElement(ElementInterface $element): bool
    {
        return $element->id && self::canQuery((int) $element->id, $element->siteId ? (int) $element->siteId : null);
    }
}

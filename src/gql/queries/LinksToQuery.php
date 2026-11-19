<?php

namespace justinholtweb\freelink\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\gql\GqlScope;
use justinholtweb\freelink\gql\types\LinkReferenceType;
use justinholtweb\freelink\Plugin;

/**
 * `freelinkLinksTo(elementId: Int!, …): [FreeLinkReference]` — reverse lookups.
 *
 * Needs the schema component "FreeLink → Query reverse link lookups". The target must be readable by
 * the schema, and every source is re-queried through Craft's own resolver for its element type, so a
 * schema only ever sees sources it could query directly.
 */
class LinksToQuery extends Query
{
    /**
     * @return array<string, mixed>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema(Plugin::GQL_SCOPE_LINKS, 'read')) {
            return [];
        }

        return [
            'freelinkLinksTo' => [
                'type' => Type::listOf(LinkReferenceType::getType()),
                'args' => [
                    'elementId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The element the links point at.'],
                    'siteId' => ['type' => Type::int(), 'description' => 'Only links in this site.'],
                    'field' => ['type' => Type::string(), 'description' => 'Only links in this FreeLink field (handle).'],
                    'type' => ['type' => Type::string(), 'description' => 'Only links of this link type.'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 20, 'description' => 'At most 100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => [self::class, 'resolve'],
                'description' => 'FreeLink links in live content that point at an element.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<int, mixed>
     */
    public static function resolve(mixed $source, array $arguments): array
    {
        $elementId = (int)$arguments['elementId'];

        // No existence oracle: a target the schema can't read has no links.
        if ($elementId <= 0 || !GqlScope::canQuery($elementId)) {
            return [];
        }

        $limit = max(1, min(100, (int)($arguments['limit'] ?? 20)));
        $offset = max(0, (int)($arguments['offset'] ?? 0));

        $result = Plugin::getInstance()->linkIndex->find([
            'targetId' => $elementId,
            'siteId' => $arguments['siteId'] ?? null,
            'field' => $arguments['field'] ?? null,
            'type' => $arguments['type'] ?? null,
        ], $limit, $offset);

        $references = $result['references'];

        // The root source must be readable by the schema. A nested entry's own visibility comes from
        // its owner (Craft's entry resolver can't query it at the root level), so its root decides.
        $bySite = [];
        foreach ($references as $ref) {
            $bySite[$ref->sourceSiteId][] = $ref->rootSourceId;
        }

        $allowed = [];
        foreach ($bySite as $siteId => $ids) {
            foreach (GqlScope::filterQueryable($ids, (int)$siteId) as $id) {
                $allowed[$siteId . ':' . $id] = true;
            }
        }

        // …and the target must be readable on the site each link resolves on (its target site, or
        // its source's site), not just on some site.
        return array_values(array_filter(
            $references,
            fn($ref) => isset($allowed[$ref->sourceSiteId . ':' . $ref->rootSourceId])
                && GqlScope::canQuery($elementId, (int)($ref->targetSiteId ?: $ref->sourceSiteId)),
        ));
    }
}

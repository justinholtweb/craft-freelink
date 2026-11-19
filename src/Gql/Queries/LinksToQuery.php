<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql\Queries;

use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\FreeLink\Gql\GqlScope;
use JustinHoltWeb\FreeLink\Gql\Types\LinkReferenceType;
use JustinHoltWeb\FreeLink\Models\LinkReference;
use JustinHoltWeb\FreeLink\Plugin;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use Override;

/**
 * `freelinkLinksTo(elementId: Int!, …): [FreeLinkReference]` — reverse lookups.
 *
 * Needs the schema component "FreeLink → Query reverse link lookups". The target must be readable by
 * the schema, and every source is re-queried through Craft's own resolver for its element type, so a
 * schema only ever sees sources it could query directly.
 */
final class LinksToQuery extends Query
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlHelper::canSchema(Plugin::GQL_SCOPE_LINKS, 'read')) {
            return [];
        }

        return [
            'freelinkLinksTo' => [
                'type' => Type::listOf(LinkReferenceType::getType()),
                'args' => [
                    'elementId' => ['name' => 'elementId', 'type' => Type::nonNull(Type::int()), 'description' => 'The element the links point at.'],
                    'siteId' => ['name' => 'siteId', 'type' => Type::int(), 'description' => 'Only links in this site.'],
                    'field' => ['name' => 'field', 'type' => Type::string(), 'description' => 'Only links in this FreeLink field (handle).'],
                    'type' => ['name' => 'type', 'type' => Type::string(), 'description' => 'Only links of this link type.'],
                    'limit' => ['name' => 'limit', 'type' => Type::int(), 'defaultValue' => 20, 'description' => 'At most 100.'],
                    'offset' => ['name' => 'offset', 'type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => self::resolve(...),
                'description' => 'FreeLink links in live content that point at an element.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<LinkReference>
     */
    public static function resolve(mixed $source, array $arguments): array
    {
        $elementId = (int) $arguments['elementId'];

        // No existence oracle: a target the schema can't read has no links.
        if ($elementId <= 0 || ! GqlScope::canQuery($elementId)) {
            return [];
        }

        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $result = app(LinkIndex::class)->find([
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
            foreach (GqlScope::filterQueryable($ids, (int) $siteId) as $id) {
                $allowed[$siteId.':'.$id] = true;
            }
        }

        return array_values(array_filter(
            $references,
            fn (LinkReference $ref) => isset($allowed[$ref->sourceSiteId.':'.$ref->rootSourceId]),
        ));
    }
}

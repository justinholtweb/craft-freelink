<?php

namespace justinholtweb\freelink\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\gql\interfaces\FreeLinkInterface;
use justinholtweb\freelink\models\LinkReference;

/**
 * `FreeLinkReference` — a link in live content that points at an element (`freelinkLinksTo`).
 */
class LinkReferenceType
{
    public static function getName(): string
    {
        return 'FreeLinkReference';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A FreeLink link that points at an element.',
            'fields' => fn() => [
                'sourceId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The element whose field holds the link (may be a nested entry).'],
                'sourceSiteId' => ['type' => Type::nonNull(Type::int())],
                'rootSourceId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The top-level element (the source itself unless it is nested).'],
                'source' => [
                    'type' => ElementInterfaceType::getType(),
                    'description' => 'The top-level source element, as the schema may read it.',
                    'resolve' => fn(LinkReference $ref) => $ref->getRootSource(),
                ],
                'field' => [
                    'type' => Type::string(),
                    'description' => 'The FreeLink field handle.',
                    'resolve' => fn(LinkReference $ref) => $ref->getFieldHandle(),
                ],
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'The link type handle.'],
                'position' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'Position in the field (0-based).',
                    'resolve' => fn(LinkReference $ref) => $ref->sortOrder,
                ],
                'status' => [
                    'type' => Type::string(),
                    'description' => 'live, missing, trashed, disabled, pending or expired.',
                    'resolve' => fn(LinkReference $ref) => $ref->getStatus(),
                ],
                'link' => [
                    'type' => FreeLinkInterface::getType(),
                    'resolve' => fn(LinkReference $ref) => $ref->getLink(),
                ],
            ],
        ]));
    }
}

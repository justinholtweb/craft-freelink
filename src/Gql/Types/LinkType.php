<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql\Types;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use JustinHoltWeb\FreeLink\Gql\Interfaces\FreeLinkInterface;

/**
 * `FreeLinkType`, the concrete type behind `FreeLinkInterface`.
 */
final class LinkType
{
    public static function getName(): string
    {
        return 'FreeLinkType';
    }

    public static function getType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn () => new ObjectType([
            'name' => self::getName(),
            'fields' => FreeLinkInterface::getFieldDefinitions(...),
            'interfaces' => fn () => [FreeLinkInterface::getType()],
        ]));
    }
}

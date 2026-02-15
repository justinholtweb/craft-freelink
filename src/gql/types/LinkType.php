<?php

namespace justinholtweb\freelink\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\gql\interfaces\FreeLinkInterface;

class LinkType
{
    public static function getName(): string
    {
        return 'FreeLinkType';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'fields' => function() {
                return FreeLinkInterface::getFieldDefinitions();
            },
            'interfaces' => [
                FreeLinkInterface::getType(),
            ],
        ]));
    }
}

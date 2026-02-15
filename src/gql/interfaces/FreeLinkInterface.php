<?php

namespace justinholtweb\freelink\gql\interfaces;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\gql\types\LinkType;

class FreeLinkInterface
{
    public static function getName(): string
    {
        return 'FreeLinkInterface';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => self::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'resolveType' => function($value) {
                return GqlEntityRegistry::getEntity(LinkType::getName()) ?? LinkType::getType();
            },
        ]));

        // Register the concrete type
        LinkType::getType();

        return $type;
    }

    public static function getFieldDefinitions(): array
    {
        return [
            'type' => [
                'name' => 'type',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The link type handle.',
            ],
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The resolved URL.',
            ],
            'text' => [
                'name' => 'text',
                'type' => Type::string(),
                'description' => 'The display text.',
            ],
            'label' => [
                'name' => 'label',
                'type' => Type::string(),
                'description' => 'The custom label.',
            ],
            'newWindow' => [
                'name' => 'newWindow',
                'type' => Type::boolean(),
                'description' => 'Whether to open in a new window.',
            ],
            'ariaLabel' => [
                'name' => 'ariaLabel',
                'type' => Type::string(),
                'description' => 'The ARIA label.',
            ],
            'title' => [
                'name' => 'title',
                'type' => Type::string(),
                'description' => 'The title attribute.',
            ],
            'urlSuffix' => [
                'name' => 'urlSuffix',
                'type' => Type::string(),
                'description' => 'The URL suffix (fragment/query).',
            ],
            'classes' => [
                'name' => 'classes',
                'type' => Type::string(),
                'description' => 'CSS classes.',
            ],
            'htmlId' => [
                'name' => 'htmlId',
                'type' => Type::string(),
                'description' => 'The HTML id attribute.',
                'resolve' => function($source) {
                    return $source->id;
                },
            ],
            'rel' => [
                'name' => 'rel',
                'type' => Type::string(),
                'description' => 'The rel attribute.',
            ],
            'target' => [
                'name' => 'target',
                'type' => Type::string(),
                'description' => 'The target attribute value.',
                'resolve' => function($source) {
                    return $source->getTarget();
                },
            ],
            'isEmpty' => [
                'name' => 'isEmpty',
                'type' => Type::boolean(),
                'description' => 'Whether the link is empty.',
                'resolve' => function($source) {
                    return $source->isEmpty();
                },
            ],
            'isElement' => [
                'name' => 'isElement',
                'type' => Type::boolean(),
                'description' => 'Whether it is an element link.',
                'resolve' => function($source) {
                    return $source->isElement();
                },
            ],
        ];
    }
}

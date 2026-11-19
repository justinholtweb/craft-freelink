<?php

namespace justinholtweb\freelink\gql\interfaces;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\gql\types\LinkType;
use justinholtweb\freelink\links\Email;
use justinholtweb\freelink\links\Site;

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

    /**
     * @return array<string, mixed>
     */
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
            'icon' => [
                'name' => 'icon',
                'type' => Type::string(),
                'description' => 'The icon name, when the field allows icons.',
            ],
            'iconSvg' => [
                'name' => 'iconSvg',
                'type' => Type::string(),
                'description' => 'The icon as inline SVG markup.',
                'resolve' => function($source) {
                    $svg = $source->getIconSvg();
                    return $svg !== null ? (string)$svg : null;
                },
            ],
            'elementId' => [
                'name' => 'elementId',
                'type' => Type::int(),
                'description' => 'The linked element’s ID (element links).',
                'resolve' => fn($source) => $source instanceof ElementLink ? $source->targetId : null,
            ],
            'elementSiteId' => [
                'name' => 'elementSiteId',
                'type' => Type::int(),
                'description' => 'The linked element’s site ID (element links).',
                'resolve' => fn($source) => $source instanceof ElementLink ? $source->targetSiteId : null,
            ],
            'elementType' => [
                'name' => 'elementType',
                'type' => Type::string(),
                'description' => 'The linked element’s class (element links).',
                'resolve' => fn($source) => $source instanceof ElementLink ? $source::elementType() : null,
            ],
            'subject' => [
                'name' => 'subject',
                'type' => Type::string(),
                'description' => 'The subject (email links).',
                'resolve' => fn($source) => $source instanceof Email ? $source->subject : null,
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The site (site links).',
                'resolve' => fn($source) => $source instanceof Site ? $source->siteId : null,
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

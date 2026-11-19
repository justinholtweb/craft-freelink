<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql\Interfaces;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Base\Link;
use JustinHoltWeb\FreeLink\Gql\Types\LinkType;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Site;

/**
 * `FreeLinkInterface` — a link's output. The target element itself is not exposed (it would bypass
 * the schema's element scopes); its ID, site and class are.
 */
final class FreeLinkInterface
{
    public static function getName(): string
    {
        return 'FreeLinkInterface';
    }

    public static function getType(): InterfaceType
    {
        $type = GqlEntityRegistry::getOrCreate(self::getName(), fn () => new InterfaceType([
            'name' => self::getName(),
            'fields' => self::getFieldDefinitions(...),
            'resolveType' => fn () => LinkType::getType(),
        ]));

        // The one concrete type has to exist before the interface resolves to it.
        LinkType::getType();

        return $type;
    }

    /**
     * Element links whose target the schema can't query resolve no URL or title.
     */
    private static function visible(Link $link): bool
    {
        return ! $link instanceof ElementLink || $link->isTargetVisible();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'type' => ['name' => 'type', 'type' => Type::nonNull(Type::string()), 'description' => 'The link type handle.'],
            'url' => ['name' => 'url', 'type' => Type::string(), 'description' => 'The resolved URL.', 'resolve' => fn (Link $l) => self::visible($l) ? $l->getUrl() : null],
            'text' => ['name' => 'text', 'type' => Type::string(), 'description' => 'The display text.', 'resolve' => fn (Link $l) => self::visible($l) ? $l->getText() : $l->label],
            'label' => ['name' => 'label', 'type' => Type::string(), 'description' => 'The custom label.'],
            'newWindow' => ['name' => 'newWindow', 'type' => Type::boolean(), 'description' => 'Whether to open in a new window.'],
            'ariaLabel' => ['name' => 'ariaLabel', 'type' => Type::string(), 'description' => 'The ARIA label.'],
            'title' => ['name' => 'title', 'type' => Type::string(), 'description' => 'The title attribute.'],
            'urlSuffix' => ['name' => 'urlSuffix', 'type' => Type::string(), 'description' => 'The URL suffix (fragment/query).'],
            'classes' => ['name' => 'classes', 'type' => Type::string(), 'description' => 'CSS classes.'],
            'htmlId' => ['name' => 'htmlId', 'type' => Type::string(), 'description' => 'The HTML id attribute.', 'resolve' => fn (Link $l) => $l->id],
            'rel' => ['name' => 'rel', 'type' => Type::string(), 'description' => 'The rel attribute.'],
            'target' => ['name' => 'target', 'type' => Type::string(), 'description' => 'The target attribute value.', 'resolve' => fn (Link $l) => $l->getTarget()],
            'isEmpty' => ['name' => 'isEmpty', 'type' => Type::boolean(), 'description' => 'Whether the link is empty.', 'resolve' => fn (Link $l) => $l->isEmpty()],
            'icon' => ['name' => 'icon', 'type' => Type::string(), 'description' => 'The icon name, when the field allows icons.'],
            'iconSvg' => [
                'name' => 'iconSvg',
                'type' => Type::string(),
                'description' => 'The icon as inline SVG markup.',
                'resolve' => fn (Link $l) => $l->getIconSvg()?->toHtml(),
            ],
            'elementId' => ['name' => 'elementId', 'type' => Type::int(), 'description' => 'The linked element’s ID (element links).', 'resolve' => fn (Link $l) => $l instanceof ElementLink ? $l->targetId : null],
            'elementSiteId' => ['name' => 'elementSiteId', 'type' => Type::int(), 'description' => 'The linked element’s site ID (element links).', 'resolve' => fn (Link $l) => $l instanceof ElementLink ? $l->targetSiteId : null],
            'elementType' => ['name' => 'elementType', 'type' => Type::string(), 'description' => 'The linked element’s class (element links).', 'resolve' => fn (Link $l) => $l instanceof ElementLink ? $l::elementType() : null],
            'subject' => ['name' => 'subject', 'type' => Type::string(), 'description' => 'The subject (email links).', 'resolve' => fn (Link $l) => $l instanceof Email ? $l->subject : null],
            'siteId' => ['name' => 'siteId', 'type' => Type::int(), 'description' => 'The site (site links).', 'resolve' => fn (Link $l) => $l instanceof Site ? $l->siteId : null],
            'isElement' => ['name' => 'isElement', 'type' => Type::boolean(), 'description' => 'Whether it is an element link.', 'resolve' => fn (Link $l) => $l->isElement()],
        ];
    }
}

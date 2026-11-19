<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql\Types\Input;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `FreeLinkInput` — one link in a FreeLink field mutation. Shared by every FreeLink field; the field's
 * own settings are enforced by validation, exactly as for a CP save.
 */
final class LinkInput
{
    public static function getName(): string
    {
        return 'FreeLinkInput';
    }

    public static function getType(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn () => new InputObjectType([
            'name' => self::getName(),
            'description' => 'A FreeLink link. Element link types take `targetId`; every other type takes `value`.',
            'fields' => fn () => [
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => 'Link type handle (url, email, phone, sms, custom, site, entry, asset, user…). Must be enabled on the field.'],
                'value' => ['type' => Type::string(), 'description' => 'URL, email address, phone number, custom value, or site path.'],
                'targetId' => ['type' => Type::int(), 'description' => 'Element ID for element link types. Null clears the target; omitting it keeps the target already at this position.'],
                'targetSiteId' => ['type' => Type::int(), 'description' => 'Site of the target element.'],
                'siteId' => ['type' => Type::int(), 'description' => 'Site for `site` links.'],
                'subject' => ['type' => Type::string(), 'description' => 'Subject for `email` links.'],
                'label' => ['type' => Type::string()],
                'newWindow' => ['type' => Type::boolean()],
                'ariaLabel' => ['type' => Type::string()],
                'title' => ['type' => Type::string()],
                'urlSuffix' => ['type' => Type::string()],
                'classes' => ['type' => Type::string()],
                'htmlId' => ['type' => Type::string(), 'description' => 'The HTML id attribute.'],
                'rel' => ['type' => Type::string()],
                'icon' => ['type' => Type::string(), 'description' => 'Icon name, when the field allows icons.'],
                'customAttributes' => ['type' => Type::listOf(self::getAttributeType())],
            ],
        ]));
    }

    public static function getAttributeType(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('FreeLinkAttributeInput', fn () => new InputObjectType([
            'name' => 'FreeLinkAttributeInput',
            'fields' => [
                'attribute' => Type::nonNull(Type::string()),
                'value' => Type::string(),
            ],
        ]));
    }
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Gql\Types;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Gql\Interfaces\FreeLinkInterface;
use JustinHoltWeb\FreeLink\Models\LinkCollection;

/**
 * A FreeLink field's GraphQL output: always a list of `FreeLinkInterface`, in either mode.
 */
final class LinkTypeGenerator
{
    /**
     * @return array<string, mixed>
     */
    public static function generateType(FreeLinkField $field): array
    {
        return [
            'name' => (string) $field->handle,
            'type' => Type::listOf(FreeLinkInterface::getType()),
            'resolve' => function (mixed $source) use ($field): array {
                $value = $source instanceof ElementInterface ? $source->getFieldValue((string) $field->handle) : null;

                return $value instanceof LinkCollection ? $value->getAll() : [];
            },
        ];
    }
}

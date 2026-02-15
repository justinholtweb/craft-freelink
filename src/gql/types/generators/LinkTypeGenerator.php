<?php

namespace justinholtweb\freelink\gql\types\generators;

use GraphQL\Type\Definition\Type;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\gql\interfaces\FreeLinkInterface;
use justinholtweb\freelink\gql\types\LinkType;

class LinkTypeGenerator
{
    /**
     * Generates the GraphQL type for a FreeLink field.
     * Returns a list type (always an array of FreeLinkInterface).
     */
    public static function generateType(FreeLinkField $field): Type|array
    {
        // Ensure types are registered
        FreeLinkInterface::getType();
        LinkType::getType();

        return [
            'name' => $field->handle,
            'type' => Type::listOf(FreeLinkInterface::getType()),
            'resolve' => function($source) use ($field) {
                $value = $source->getFieldValue($field->handle);

                if ($value === null) {
                    return [];
                }

                return $value->getAll();
            },
        ];
    }
}

<?php

namespace justinholtweb\freelink\gql\arguments;

use GraphQL\Type\Definition\Type;

class FreeLinkArguments
{
    /**
     * Returns the argument definitions for querying FreeLink fields.
     */
    public static function getArguments(): array
    {
        return [
            'type' => [
                'name' => 'type',
                'type' => Type::string(),
                'description' => 'Filter by link type handle (e.g., "url", "entry").',
            ],
            'isEmpty' => [
                'name' => 'isEmpty',
                'type' => Type::boolean(),
                'description' => 'Filter by whether the link is empty.',
            ],
        ];
    }
}

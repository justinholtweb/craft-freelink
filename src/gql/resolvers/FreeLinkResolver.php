<?php

namespace justinholtweb\freelink\gql\resolvers;

use craft\gql\base\Resolver;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\freelink\models\LinkCollection;

class FreeLinkResolver extends Resolver
{
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $fieldName = $resolveInfo->fieldName;
        $value = $source->$fieldName ?? null;

        if ($value instanceof LinkCollection) {
            return $value->getAll();
        }

        return [];
    }
}

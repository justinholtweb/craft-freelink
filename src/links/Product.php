<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\ElementLink;

class Product extends ElementLink
{
    public static function displayName(): string
    {
        return 'Product';
    }

    public static function handle(): string
    {
        return 'product';
    }

    public static function elementType(): string
    {
        return 'craft\commerce\elements\Product';
    }

    /**
     * Whether Commerce is installed and this type is available.
     */
    public static function isAvailable(): bool
    {
        return class_exists('craft\commerce\elements\Product');
    }
}

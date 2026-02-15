<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\ElementLink;

class Variant extends ElementLink
{
    public static function displayName(): string
    {
        return 'Variant';
    }

    public static function handle(): string
    {
        return 'variant';
    }

    public static function elementType(): string
    {
        return 'craft\commerce\elements\Variant';
    }

    public static function isAvailable(): bool
    {
        return class_exists('craft\commerce\elements\Variant');
    }
}

<?php

namespace justinholtweb\freelink\links;

use craft\elements\Asset as AssetElement;
use justinholtweb\freelink\base\ElementLink;

class Asset extends ElementLink
{
    public static function displayName(): string
    {
        return 'Asset';
    }

    public static function handle(): string
    {
        return 'asset';
    }

    public static function elementType(): string
    {
        return AssetElement::class;
    }
}

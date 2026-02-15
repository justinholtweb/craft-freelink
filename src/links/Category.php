<?php

namespace justinholtweb\freelink\links;

use craft\elements\Category as CategoryElement;
use justinholtweb\freelink\base\ElementLink;

class Category extends ElementLink
{
    public static function displayName(): string
    {
        return 'Category';
    }

    public static function handle(): string
    {
        return 'category';
    }

    public static function elementType(): string
    {
        return CategoryElement::class;
    }
}

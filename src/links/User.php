<?php

namespace justinholtweb\freelink\links;

use craft\elements\User as UserElement;
use justinholtweb\freelink\base\ElementLink;

class User extends ElementLink
{
    public static function displayName(): string
    {
        return 'User';
    }

    public static function handle(): string
    {
        return 'user';
    }

    public static function elementType(): string
    {
        return UserElement::class;
    }
}

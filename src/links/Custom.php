<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Custom extends Link
{
    public static function displayName(): string
    {
        return 'Custom';
    }

    public static function handle(): string
    {
        return 'custom';
    }

    public static function inputPlaceholder(): string
    {
        return '/path or javascript:void(0)';
    }
}

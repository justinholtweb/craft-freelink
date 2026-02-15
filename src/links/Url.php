<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Url extends Link
{
    public static function displayName(): string
    {
        return 'URL';
    }

    public static function handle(): string
    {
        return 'url';
    }

    public static function inputPlaceholder(): string
    {
        return 'https://';
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['value', 'url', 'defaultScheme' => 'https'];

        return $rules;
    }
}

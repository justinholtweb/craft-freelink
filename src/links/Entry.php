<?php

namespace justinholtweb\freelink\links;

use craft\elements\Entry as EntryElement;
use justinholtweb\freelink\base\ElementLink;

class Entry extends ElementLink
{
    public static function displayName(): string
    {
        return 'Entry';
    }

    public static function handle(): string
    {
        return 'entry';
    }

    public static function elementType(): string
    {
        return EntryElement::class;
    }
}

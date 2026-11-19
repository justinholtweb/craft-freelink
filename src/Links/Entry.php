<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use CraftCms\Cms\Entry\Elements\Entry as EntryElement;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

class Entry extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('Entry', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'entry';
    }

    #[Override]
    public static function elementType(): string
    {
        return EntryElement::class;
    }
}

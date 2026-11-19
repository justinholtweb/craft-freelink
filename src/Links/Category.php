<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

/**
 * Category links, kept so FreeLink 5 values still load.
 *
 * Craft 6 has no categories: Craft 5's `entrify/categories` turns them into entries, keeping their
 * element IDs, and FreeLink's Craft 6 upgrade migration turns category links whose target is now an
 * entry into entry links. This type is never offered for new links.
 */
class Category extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('Category', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'category';
    }

    /** The Craft 5 category element class, which no longer exists. */
    public const string LEGACY_ELEMENT_TYPE = 'craft\\elements\\Category';

    #[Override]
    public static function elementType(): string
    {
        return self::LEGACY_ELEMENT_TYPE;
    }
}

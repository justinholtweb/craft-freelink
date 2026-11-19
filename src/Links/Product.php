<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

/**
 * Commerce product links.
 *
 * TODO: Commerce 6 isn't released; confirm its product element class name when it is. Until then the
 * type is unavailable (never offered) and stored product links load as empty links.
 */
class Product extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('Product', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'product';
    }

    public const string ELEMENT_TYPE = 'CraftCms\\Commerce\\Elements\\Product';

    #[Override]
    public static function elementType(): string
    {
        return self::ELEMENT_TYPE;
    }
}

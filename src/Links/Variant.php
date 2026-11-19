<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

/**
 * Commerce variant links.
 *
 * TODO: Commerce 6 isn't released; confirm its variant element class name when it is.
 */
class Variant extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('Variant', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'variant';
    }

    public const string ELEMENT_TYPE = 'CraftCms\\Commerce\\Elements\\Variant';

    #[Override]
    public static function elementType(): string
    {
        return self::ELEMENT_TYPE;
    }
}

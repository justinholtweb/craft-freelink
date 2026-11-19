<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use CraftCms\Cms\Asset\Elements\Asset as AssetElement;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

class Asset extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('Asset', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'asset';
    }

    #[Override]
    public static function elementType(): string
    {
        return AssetElement::class;
    }
}

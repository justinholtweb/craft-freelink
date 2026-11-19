<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\Link;
use Override;

use function CraftCms\Cms\t;

class Custom extends Link
{
    #[Override]
    public static function displayName(): string
    {
        return t('Custom', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'custom';
    }

    #[Override]
    public static function inputPlaceholder(): string
    {
        return '/path, #anchor or javascript:void(0)';
    }
}

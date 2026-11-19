<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use CraftCms\Cms\User\Elements\User as UserElement;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use Override;

use function CraftCms\Cms\t;

class User extends ElementLink
{
    #[Override]
    public static function displayName(): string
    {
        return t('User', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'user';
    }

    #[Override]
    public static function elementType(): string
    {
        return UserElement::class;
    }
}

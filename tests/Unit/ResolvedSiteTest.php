<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Links\Entry;
use PHPUnit\Framework\TestCase;

/**
 * The site a link target is resolved on is the site it is checked on: ElementLink::resolvedSiteId().
 */
class ResolvedSiteTest extends TestCase
{
    public function test_target_site_wins(): void
    {
        $link = new Entry;
        $link->targetId = 5;
        $link->targetSiteId = 3;
        $link->ownerSiteId = 2;

        self::assertSame(3, $link->resolvedSiteId());
    }

    public function test_owner_site_without_a_target_site(): void
    {
        $link = new Entry;
        $link->targetId = 5;
        $link->ownerSiteId = 2;

        self::assertSame(2, $link->resolvedSiteId());
    }

    public function test_current_site_last(): void
    {
        $link = new Entry;
        $link->targetId = 5;

        // No Craft app in unit tests: the current site can't be read, and nothing is guessed.
        self::assertNull($link->resolvedSiteId());
    }

    public function test_owner_site_is_not_stored(): void
    {
        $link = new Entry;
        $link->type = 'entry';
        $link->targetId = 5;
        $link->ownerSiteId = 2;

        self::assertArrayNotHasKey('ownerSiteId', $link->toArray());
        self::assertSame('entry|#5|0', FreeLinkField::linkKey($link), 'the grandfathering key uses the stored target site');
    }
}

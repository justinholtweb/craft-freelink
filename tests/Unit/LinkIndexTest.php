<?php

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Models\LinkCollection;
use JustinHoltWeb\FreeLink\Models\Settings;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of the link index: rows a value produces, hosts, domain filters; and the settings
 * SVG check.
 */
class LinkIndexTest extends TestCase
{
    public function test_host_of(): void
    {
        self::assertSame('example.com', LinkIndex::hostOf('https://Example.COM./a?b'));
        self::assertSame('sub.example.com', LinkIndex::hostOf('http://sub.example.com:8080/'));
        self::assertNull(LinkIndex::hostOf('mailto:a@b.test'));
        self::assertNull(LinkIndex::hostOf('/relative'));
        self::assertNull(LinkIndex::hostOf('ftp://example.com/'));
    }

    public function test_normalize_domain(): void
    {
        self::assertSame('example.com', LinkIndex::normalizeDomain(' Example.com '));
        self::assertSame('example.com', LinkIndex::normalizeDomain('www.example.com'));
        self::assertSame('example.com', LinkIndex::normalizeDomain('https://www.example.com/path'));
        self::assertNull(LinkIndex::normalizeDomain('exa%mple.com'));
        self::assertNull(LinkIndex::normalizeDomain("x' OR 1=1"));
        self::assertNull(LinkIndex::normalizeDomain(['x']));
    }

    public function test_rows_for(): void
    {

        $links = app(Links::class);
        $value = new LinkCollection([
            $links->createLink(['type' => 'url', 'value' => 'https://Example.com/a', 'urlSuffix' => '#x', 'label' => 'A']),
            $links->createLink(['type' => 'url', 'value' => '']),
            $links->createLink(['type' => 'entry', 'targetId' => 5, 'targetSiteId' => 1]),
            $links->createLink(['type' => 'email', 'value' => 'a@b.test']),
            $links->createLink(['type' => 'custom', 'value' => 'javascript:alert(1)']),
        ]);

        $rows = app(LinkIndex::class)->rowsFor($value);

        self::assertCount(4, $rows, 'empty link skipped');
        self::assertSame(0, $rows[0]['sortOrder']);
        self::assertSame('https://Example.com/a#x', $rows[0]['url']);
        self::assertSame('example.com', $rows[0]['host']);
        self::assertSame(sha1('https://Example.com/a#x'), $rows[0]['urlHash']);
        self::assertSame(2, $rows[1]['sortOrder'], 'position in the field, not in the rows');
        self::assertTrue($rows[1]['isElement']);
        self::assertSame(5, $rows[1]['targetId']);
        self::assertNull($rows[1]['url']);
        self::assertSame('mailto:a@b.test', $rows[2]['url']);
        self::assertNull($rows[2]['host'], 'email links are never checked');
        self::assertNull($rows[3]['url'], 'unsafe URLs are never indexed as URLs');
        self::assertNull($rows[3]['host']);
    }

    public function test_svg_markup(): void
    {
        self::assertTrue(Settings::isSvgMarkup('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>'));
        self::assertTrue(Settings::isSvgMarkup('<?xml version="1.0"?><svg viewBox="0 0 1 1"></svg>'));
        self::assertFalse(Settings::isSvgMarkup('<div>no</div>'));
        self::assertFalse(Settings::isSvgMarkup('<svg><path></svg>'), 'malformed');
        self::assertFalse(Settings::isSvgMarkup('<svg></svg><script>alert(1)</script>'));
        self::assertFalse(Settings::isSvgMarkup('hello <svg></svg>'));
    }
}

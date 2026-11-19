<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\helpers\UrlSafety;
use justinholtweb\freelink\links\Custom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Custom attribute names are an allowlist, the same as FreeNav 5.2.1 (its case table, ported), and
 * values must be text.
 */
class CustomAttributeAllowlistTest extends TestCase
{
    /**
     * @return list<array{string, bool}>
     */
    public static function attributeNames(): array
    {
        return [
            ['title', true],
            ['rel', true],
            ['id', true],
            ['lang', true],
            ['role', true],
            ['itemprop', true],
            ['TITLE', true],
            ['aria-label', true],
            ['data-track', true],
            ['data-nav-item', true],
            ['data-tracking_id', true],
            ['onmouseover', false],
            ['onclick', false],
            ['OnLoad', false],
            ['href', false],
            ['src', false],
            ['srcdoc', false],
            ['formaction', false],
            ['style', false],
            ['xlink:href', false],
            ['class', false],
            ['x-init', false],
            ['@click', false],
            [':class', false],
            ['hx-on:click', false],
            ['hx-get', false],
            ['data-hx-get', false],
            ['v-html', false],
            ['ng-click', false],
            ['data-ng-click', false],
            ['data-x-data', false],
            ['data-v-bind', false],
            ['data-on-click', false],
            ['data-bind', false],
            ['data-attr-href', false],
            ['data-signals', false],
            ['data-json-signals', false],
            ['data-text', false],
            ['data-show', false],
            ['data-class', false],
            ['data-style', false],
            ['data-star-on-click', false],
            ['data-teleport', false],
            ['aria', false],
            ['data', false],
            ['ng', false],
            ['data-', false],
            ['', false],
            ['data track', false],
            ['data-"x"', false],
            // FreeLink extras: the rest of the plain allowlist
            ['hreflang', true],
            ['referrerpolicy', true],
            ['download', true],
        ];
    }

    #[DataProvider('attributeNames')]
    public function testAllowlist(string $name, bool $safe): void
    {
        self::assertSame($safe, UrlSafety::isSafeAttributeName($name), $name);
    }

    public function testNonStringNamesAreUnsafe(): void
    {
        self::assertFalse(UrlSafety::isSafeAttributeName(null));
        self::assertFalse(UrlSafety::isSafeAttributeName(['data-x']));
        self::assertFalse(UrlSafety::isSafeAttributeName(1));
    }

    public function testValuesMustBeText(): void
    {
        self::assertSame('nav', UrlSafety::customAttributeValue('nav'));
        self::assertSame('3', UrlSafety::customAttributeValue(3));
        self::assertSame('1', UrlSafety::customAttributeValue(true));
        self::assertSame('', UrlSafety::customAttributeValue(null));
        self::assertNull(UrlSafety::customAttributeValue(['onclick' => 'x']));
        self::assertNull(UrlSafety::customAttributeValue(new \stdClass()));
    }

    public function testSafeCustomAttributesFiltersAndKeepsOrder(): void
    {
        $filtered = UrlSafety::safeCustomAttributes([
            ['attribute' => 'data-track', 'value' => 'nav'],
            ['attribute' => 'onmouseover', 'value' => 'alert(1)'],
            ['attribute' => 'style', 'value' => 'display:none'],
            'not-an-object',
            ['attribute' => 'data', 'value' => ['onclick' => 'alert(1)']],
            ['attribute' => 'data-ok', 'value' => ['nested']],
            ['key' => 'aria-label', 'value' => 'Menu'],
            ['attribute' => 'data-on-click', 'value' => '$x=1'],
            ['attribute' => ' title ', 'value' => 7],
        ]);

        self::assertSame([
            ['attribute' => 'data-track', 'value' => 'nav'],
            ['attribute' => 'aria-label', 'value' => 'Menu'],
            ['attribute' => 'title', 'value' => '7'],
        ], $filtered);
    }

    public function testStoredUnsafeAttributesAreSkippedOnRender(): void
    {
        $link = new Custom();
        $link->type = 'custom';
        $link->value = 'https://example.com/';
        $link->customAttributes = [
            ['attribute' => 'data-track', 'value' => 'cta'],
            ['attribute' => 'x-init', 'value' => 'alert(1)'],
            ['attribute' => 'class', 'value' => 'evil'],
            ['attribute' => 'data-hx-get', 'value' => '/admin'],
            ['attribute' => 'data-x', 'value' => ['onclick' => 'alert(2)']],
        ];

        self::assertSame(['data-track' => 'cta'], $link->getSafeCustomAttributes());
    }

    public function testValidationRefusesUnsafeNamesAndNonTextValues(): void
    {
        if (!class_exists('Craft', false) || \Craft::$app === null) {
            self::markTestSkipped('needs a booted Craft app for messages');
        }

        $link = new Custom();
        $link->type = 'custom';
        $link->value = 'https://example.com/';

        $link->customAttributes = [['attribute' => 'data-on-click', 'value' => 'x']];
        self::assertFalse($link->validate());

        $link->customAttributes = [['attribute' => 'data-track', 'value' => ['a' => 'b']]];
        self::assertFalse($link->validate());

        $link->customAttributes = [['attribute' => 'data-track', 'value' => 'ok'], ['attribute' => 'aria-label', 'value' => 'x']];
        self::assertTrue($link->validate());
    }
}

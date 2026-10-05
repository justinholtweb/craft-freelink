<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\helpers\UrlSafety;
use justinholtweb\freelink\links\Custom;
use justinholtweb\freelink\links\Url;
use justinholtweb\freelink\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an editor can make a link do on the front end.
 *
 * Until 5.1.2 anybody who could edit an entry could save a Custom link to `javascript:…`, or a
 * custom attribute named `onmouseover`, and `getLink()` rendered both into the page — stored XSS
 * from the editor role. Custom attributes also rendered with the field's Advanced section off,
 * where nobody could see them.
 */
class LinkSafetyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'leading space' => ['  javascript:alert(1)'],
            'tab inside the scheme' => ["java\tscript:alert(1)"],
            'newline inside the scheme' => ["java\nscript:alert(1)"],
            'leading control character' => ["\x01javascript:alert(1)"],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'no-op with a payload after it' => ['javascript:void(0);alert(1)'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function safeUrls(): array
    {
        return [
            'https' => ['https://example.com/'],
            'relative path' => ['/about'],
            'anchor' => ['#contact'],
            'mailto' => ['mailto:hello@example.com'],
            'tel' => ['tel:+15555550100'],
            'void(0)' => ['javascript:void(0)'],
            'void(0);' => ['javascript:void(0);'],
            'void 0' => ['javascript:void 0'],
            'empty statement' => ['javascript:;'],
            'colon later in a path' => ['/a:b'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlIsRecognised(string $url): void
    {
        self::assertTrue(UrlSafety::isUnsafeUrl($url));
    }

    #[DataProvider('safeUrls')]
    public function testSafeUrlIsLeftAlone(string $url): void
    {
        self::assertFalse(UrlSafety::isUnsafeUrl($url));
    }

    #[DataProvider('unsafeUrls')]
    public function testCustomLinkNeverRendersAScriptUrl(string $url): void
    {
        $link = $this->custom($url);

        self::assertNull($link->getUrl());
        self::assertSame('', (string)$link);
        self::assertNull($link->getLink());
        self::assertNull($link->toApiArray()['url']);
    }

    public function testCustomLinkWithAScriptUrlFailsValidation(): void
    {
        $link = $this->custom('javascript:alert(document.cookie)');

        self::assertFalse($link->validate());
        self::assertArrayHasKey('value', $link->getErrors());
    }

    public function testUrlSuffixCannotSmuggleOneIn(): void
    {
        // A suffix can't change the scheme, but the check runs on the URL with the suffix applied.
        $link = $this->custom('javascript:');
        $link->urlSuffix = 'alert(1)';

        self::assertNull($link->getUrl());
        self::assertFalse($link->validate());
    }

    public function testCustomLinkWithASafeUrlStillWorks(): void
    {
        $link = $this->custom('javascript:void(0)');

        self::assertTrue($link->validate());
        self::assertSame('javascript:void(0)', $link->getUrl());
    }

    /** @return array<string, array{string}> */
    public static function unsafeAttributeNames(): array
    {
        return [
            'event handler' => ['onmouseover'],
            'event handler, upper case' => ['ONCLICK'],
            'replaces the destination' => ['href'],
            'replaces the destination, SVG' => ['xlink:href'],
            'breaks out of the tag' => ['x" onclick="alert(1)'],
            'space' => ['data-x onclick'],
            'starts with a digit' => ['1x'],
            'formaction' => ['formaction'],
        ];
    }

    #[DataProvider('unsafeAttributeNames')]
    public function testUnsafeAttributeNameIsRefusedAndNeverRendered(string $name): void
    {
        $link = $this->custom('https://example.com/', [['attribute' => $name, 'value' => 'alert(1)']]);

        self::assertFalse(UrlSafety::isSafeAttributeName($name));
        self::assertFalse($link->validate());
        self::assertArrayHasKey('customAttributes', $link->getErrors());
        self::assertStringNotContainsString('alert(1)', (string)$link->getLink());
    }

    public function testOrdinaryAttributesStillRender(): void
    {
        $link = $this->custom('https://example.com/', [
            ['attribute' => 'data-track', 'value' => 'nav'],
            ['attribute' => 'aria-describedby', 'value' => 'hint'],
            ['attribute' => 'hreflang', 'value' => 'de'],
            ['attribute' => 'onmouseover', 'value' => 'alert(1)'],
        ]);

        $html = (string)$link->getLink();

        self::assertStringContainsString('data-track="nav"', $html);
        self::assertStringContainsString('aria-describedby="hint"', $html);
        self::assertStringContainsString('hreflang="de"', $html);
        self::assertStringNotContainsString('onmouseover', $html);
    }

    public function testCustomAttributesCannotOverrideTheLinksOwnAttributes(): void
    {
        $link = $this->custom('https://example.com/', [
            ['attribute' => 'target', 'value' => '_self'],
            ['attribute' => 'rel', 'value' => 'opener'],
        ]);
        $link->newWindow = true;

        $html = (string)$link->getLink();

        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('noopener', $html);
        self::assertStringNotContainsString('_self', $html);
    }

    public function testUrlTypeIsCoveredAtRenderTimeToo(): void
    {
        // A migration or the API writes links without validating them.
        $link = new Url();
        $link->type = 'url';
        $link->value = 'javascript:alert(1)';

        self::assertNull($link->getUrl());
        self::assertFalse($link->validate());
    }

    public function testCustomAttributesAreDroppedWhenAdvancedIsOff(): void
    {
        if (Plugin::getInstance() === null) {
            self::markTestSkipped('FreeLink plugin instance not available (no booted Craft app).');
        }

        $row = [
            'type' => 'url',
            'values' => ['url' => 'https://example.com/'],
            'customAttributes' => [['attribute' => 'data-track', 'value' => 'nav']],
        ];

        $off = new FreeLinkField(['showAdvanced' => false]);
        $on = new FreeLinkField(['showAdvanced' => true]);

        self::assertSame([], $off->normalizeValue($row)->first()->customAttributes);
        self::assertStringNotContainsString('data-track', (string)$off->normalizeValue($row)->first()->getLink());
        self::assertSame('nav', $on->normalizeValue($row)->first()->customAttributes[0]['value']);
    }

    /**
     * @param list<array<string, mixed>> $attributes
     */
    private function custom(string $value, array $attributes = []): Custom
    {
        $link = new Custom();
        $link->type = 'custom';
        $link->value = $value;
        $link->customAttributes = $attributes;

        return $link;
    }
}

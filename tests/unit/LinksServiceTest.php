<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\links\Entry;
use justinholtweb\freelink\links\Url;
use justinholtweb\freelink\services\Links;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Links registry service: type registration and link creation.
 */
class LinksServiceTest extends TestCase
{
    private Links $links;

    protected function setUp(): void
    {
        $this->links = new Links();
    }

    public function testAllBuiltInTypesAreRegistered(): void
    {
        $types = $this->links->getAllTypes();

        $expected = [
            'url', 'email', 'phone', 'sms', 'custom', 'site',
            'entry', 'asset', 'category', 'user', 'product', 'variant',
        ];

        foreach ($expected as $handle) {
            self::assertArrayHasKey($handle, $types, "Missing link type: {$handle}");
        }

        self::assertCount(12, $types);
    }

    public function testGetTypeByHandle(): void
    {
        self::assertSame(Url::class, $this->links->getTypeByHandle('url'));
        self::assertSame(Entry::class, $this->links->getTypeByHandle('entry'));
        self::assertNull($this->links->getTypeByHandle('does-not-exist'));
    }

    public function testCreateSimpleLink(): void
    {
        $link = $this->links->createLink([
            'type' => 'url',
            'value' => 'https://example.com',
            'label' => 'Example',
            'newWindow' => true,
        ]);

        self::assertInstanceOf(Url::class, $link);
        self::assertSame('https://example.com', $link->value);
        self::assertSame('Example', $link->label);
        self::assertTrue($link->newWindow);
    }

    public function testCreateElementLinkSetsTarget(): void
    {
        $link = $this->links->createLink([
            'type' => 'entry',
            'targetId' => 42,
            'targetSiteId' => 1,
        ]);

        self::assertInstanceOf(ElementLink::class, $link);
        self::assertSame(42, $link->targetId);
        self::assertSame(1, $link->targetSiteId);
        self::assertTrue($link->isElement());
    }

    public function testCreateLinkReturnsNullForUnknownType(): void
    {
        self::assertNull($this->links->createLink(['type' => 'nope']));
        self::assertNull($this->links->createLink([]));
    }

    public function testCreateLinkCastsNewWindowToBool(): void
    {
        $link = $this->links->createLink([
            'type' => 'url',
            'value' => 'https://example.com',
            'newWindow' => '1',
        ]);

        self::assertTrue($link->newWindow);
    }

    public function testCustomTypesCanBeRegisteredViaEvent(): void
    {
        $links = new Links();
        $links->on(Links::EVENT_REGISTER_LINK_TYPES, function($event) {
            $event->types[] = StubCustomLink::class;
        });

        $types = $links->getAllTypes();

        self::assertArrayHasKey('stub-custom', $types);
        self::assertSame(StubCustomLink::class, $types['stub-custom']);
    }
}

/**
 * Minimal custom link type used to verify event-based registration.
 */
class StubCustomLink extends Url
{
    public static function displayName(): string
    {
        return 'Stub';
    }

    public static function handle(): string
    {
        return 'stub-custom';
    }
}

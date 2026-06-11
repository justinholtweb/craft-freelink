<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\links\Email;
use justinholtweb\freelink\links\Url;
use justinholtweb\freelink\models\LinkCollection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LinkCollection field value object and its transparent
 * single-link proxy behaviour.
 */
class LinkCollectionTest extends TestCase
{
    private function url(string $value, ?string $label = null): Url
    {
        $link = new Url();
        $link->type = 'url';
        $link->value = $value;
        $link->label = $label;

        return $link;
    }

    public function testEmptyCollection(): void
    {
        $collection = new LinkCollection();

        self::assertCount(0, $collection);
        self::assertTrue($collection->isEmpty());
        self::assertNull($collection->first());
        self::assertNull($collection->one());
        self::assertSame('', (string)$collection);
    }

    public function testCountAndIteration(): void
    {
        $collection = new LinkCollection([
            $this->url('https://a.com'),
            $this->url('https://b.com'),
        ]);

        self::assertCount(2, $collection);

        $urls = [];
        foreach ($collection as $link) {
            $urls[] = $link->getUrl();
        }

        self::assertSame(['https://a.com', 'https://b.com'], $urls);
        self::assertSame($collection->getAll(), $collection->all());
    }

    public function testProxyGetterDelegatesToFirstLink(): void
    {
        $collection = new LinkCollection([
            $this->url('https://example.com', 'Example'),
            $this->url('https://other.com'),
        ]);

        // __get → getUrl()/getText() on the first link
        self::assertSame('https://example.com', $collection->url);
        self::assertSame('Example', $collection->text);
        // Direct property proxy
        self::assertSame('url', $collection->type);
        self::assertSame('https://example.com', (string)$collection);
    }

    public function testProxyMethodCallDelegatesToFirstLink(): void
    {
        $collection = new LinkCollection([$this->url('https://example.com', 'Example')]);

        $html = (string)$collection->getLink(['class' => 'btn']);

        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('class="btn"', $html);
    }

    public function testIssetProxy(): void
    {
        $collection = new LinkCollection([$this->url('https://example.com')]);

        self::assertTrue(isset($collection->url));

        $empty = new LinkCollection();
        self::assertFalse(isset($empty->url));
    }

    public function testIsEmptyIgnoresEmptyLinks(): void
    {
        $collection = new LinkCollection([new Url()]);

        self::assertCount(1, $collection);
        self::assertTrue($collection->isEmpty());
    }

    public function testFilterReturnsNewCollection(): void
    {
        $email = new Email();
        $email->type = 'email';
        $email->value = 'hi@example.com';

        $collection = new LinkCollection([
            $this->url('https://example.com'),
            $email,
        ]);

        $filtered = $collection->filter(fn($link) => $link->type === 'email');

        self::assertInstanceOf(LinkCollection::class, $filtered);
        self::assertCount(1, $filtered);
        self::assertSame('mailto:hi@example.com', $filtered->first()->getUrl());
    }

    public function testArrayAccess(): void
    {
        $collection = new LinkCollection([
            $this->url('https://a.com'),
            $this->url('https://b.com'),
        ]);

        self::assertTrue(isset($collection[0]));
        self::assertFalse(isset($collection[5]));
        self::assertSame('https://b.com', $collection[1]->getUrl());

        unset($collection[0]);
        self::assertCount(1, $collection);
        self::assertSame('https://b.com', $collection[0]->getUrl());
    }

    public function testJsonSerializeProducesArrayOfApiObjects(): void
    {
        $collection = new LinkCollection([
            $this->url('https://a.com', 'A'),
            $this->url('https://b.com', 'B'),
        ]);

        $decoded = json_decode(json_encode($collection), true);

        self::assertCount(2, $decoded);
        self::assertSame('https://a.com', $decoded[0]['url']);
        self::assertSame('A', $decoded[0]['text']);
        self::assertSame('https://b.com', $decoded[1]['url']);
    }
}

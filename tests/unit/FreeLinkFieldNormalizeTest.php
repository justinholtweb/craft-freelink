<?php

namespace justinholtweb\freelink\tests\unit;

use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\links\Url;
use justinholtweb\freelink\models\LinkCollection;
use justinholtweb\freelink\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for FreeLinkField::normalizeValue() against the exact POST
 * shape the CP form produces.
 *
 * The control-panel form (field/_link-block.twig) namespaces its inputs by
 * handle — values[<type>] for scalar links and elements[<type>] for element
 * selectors — rather than posting flat value/targetId keys. normalizeValue()
 * must map that shape onto the flat keys createLink() reads, or every link
 * saved through the CP comes out with a null destination.
 *
 * These tests exercise the POST path with a null owner element (which skips the
 * relations-table lookup), so they need only a booted Craft app for the plugin
 * services — no database rows.
 */
class FreeLinkFieldNormalizeTest extends TestCase
{
    private FreeLinkField $field;

    protected function setUp(): void
    {
        if (Plugin::getInstance() === null) {
            self::markTestSkipped('FreeLink plugin instance not available (no booted Craft app).');
        }

        $this->field = new FreeLinkField();
    }

    public function testSingleScalarLinkPostShapeMapsToValue(): void
    {
        // Single-link mode posts a bare associative array with a `type` key.
        $value = $this->field->normalizeValue([
            'type' => 'url',
            'values' => ['url' => 'https://example.com/post-shape'],
            'label' => 'Example',
        ]);

        self::assertInstanceOf(LinkCollection::class, $value);
        self::assertCount(1, $value);

        $link = $value->first();
        self::assertInstanceOf(Url::class, $link);
        self::assertSame('https://example.com/post-shape', $link->value);
        self::assertSame('Example', $link->label);
    }

    public function testMultiLinkPostShapePicksValueByType(): void
    {
        // Multi-link mode posts a list; each link only fills the values[<type>]
        // slot matching its own type.
        $value = $this->field->normalizeValue([
            ['type' => 'url', 'values' => ['url' => 'https://example.com']],
            ['type' => 'email', 'values' => ['email' => 'hello@example.com']],
        ]);

        self::assertCount(2, $value);

        $links = $value->getAll();
        self::assertSame('url', $links[0]->type);
        self::assertSame('https://example.com', $links[0]->value);
        self::assertSame('email', $links[1]->type);
        self::assertSame('hello@example.com', $links[1]->value);
    }

    public function testEmailPostShapeCapturesSubject(): void
    {
        // The email subject posts as a flat [subject] key alongside the
        // handle-namespaced values[email] input.
        $value = $this->field->normalizeValue([
            'type' => 'email',
            'values' => ['email' => 'hello@example.com'],
            'subject' => 'Quote request',
        ]);

        $link = $value->first();
        self::assertSame('hello@example.com', $link->value);
        self::assertSame('Quote request', $link->subject);
        self::assertSame(
            'mailto:hello@example.com?subject=Quote%20request',
            $link->getUrl(),
        );
    }

    public function testElementLinkPostShapeCapturesTargetId(): void
    {
        // elementSelect posts the selected id(s) as an array under elements[<type>].
        $value = $this->field->normalizeValue([
            'type' => 'entry',
            'elements' => ['entry' => ['42']],
        ]);

        $link = $value->first();
        self::assertInstanceOf(ElementLink::class, $link);
        self::assertSame(42, $link->targetId);
    }

    public function testClearedElementLinkYieldsNullTarget(): void
    {
        // Removing the selection posts an empty elements[<type>] array; the link
        // must come back with no target rather than resurrecting a prior one.
        $value = $this->field->normalizeValue([
            'type' => 'entry',
            'elements' => ['entry' => []],
        ]);

        $link = $value->first();
        self::assertInstanceOf(ElementLink::class, $link);
        self::assertNull($link->targetId);
    }

    public function testExplicitFlatValueIsNotClobbered(): void
    {
        // Data loaded from the content column arrives with a flat `value` key and
        // no values[] wrapper; the POST mapping must leave it untouched.
        $value = $this->field->normalizeValue([
            'type' => 'url',
            'value' => 'https://example.com/from-db',
        ]);

        self::assertSame('https://example.com/from-db', $value->first()->value);
    }
}

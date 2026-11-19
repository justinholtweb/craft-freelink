<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Fields\LinkControl;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Entry;
use JustinHoltWeb\FreeLink\Links\Phone;
use JustinHoltWeb\FreeLink\Models\LinkCollection;
use JustinHoltWeb\FreeLink\Services\Links;
use PHPUnit\Framework\TestCase;

/**
 * The CP editor's value mapping: FreeLink JSON ⇄ rows of Craft's Link control.
 */
class LinkControlTest extends TestCase
{
    private FreeLinkField $field;

    protected function setUp(): void
    {
        $this->field = new FreeLinkField([
            'handle' => 'cta',
            'linkTypes' => [
                'url' => ['enabled' => true, 'sortOrder' => 0],
                'entry' => ['enabled' => true, 'sortOrder' => 1],
                'email' => ['enabled' => true, 'sortOrder' => 2],
                'phone' => ['enabled' => true, 'sortOrder' => 3],
                'custom' => ['enabled' => true, 'sortOrder' => 4],
            ],
            'multipleLinks' => true,
            'showAdvanced' => true,
            'allowIcons' => true,
        ]);
    }

    public function test_control_rows_are_told_apart_from_stored_json(): void
    {
        self::assertTrue(LinkControl::isControlValue([['link' => ['type' => 'url', 'value' => 'https://x.test']]]));
        self::assertFalse(LinkControl::isControlValue(['type' => 'url', 'value' => 'https://x.test']));
        self::assertFalse(LinkControl::isControlValue([['type' => 'url', 'value' => 'https://x.test']]));
        self::assertFalse(LinkControl::isControlValue([]));
    }

    public function test_posted_rows_become_links(): void
    {
        $value = $this->field->normalizeValue([
            ['link' => ['type' => 'url', 'value' => 'https://a.test', 'label' => 'A', 'target' => '_blank', 'class' => 'btn', 'urlSuffix' => '#x'], 'icon' => 'house', 'attributes' => "data-track=cta\ndata-x=\"1\""],
            ['link' => ['type' => 'entry', 'value' => '{entry:42@2:url}']],
            ['link' => ['type' => 'email', 'value' => 'mailto:hi@b.test?subject=Hello%20there']],
            ['link' => ['type' => 'phone', 'value' => 'tel:+1 555 123']],
            ['link' => ['type' => 'entry', 'value' => '']],
            ['link' => ['type' => 'nope', 'value' => 'x']],
        ]);

        self::assertCount(5, $value, 'unknown type dropped');
        [$url, $entry, $email, $phone, $empty] = $value->getAll();

        self::assertSame('https://a.test', $url->value);
        self::assertSame('A', $url->label);
        self::assertTrue($url->newWindow);
        self::assertSame('btn', $url->classes);
        self::assertSame('#x', $url->urlSuffix);
        self::assertSame('house', $url->icon);
        self::assertSame([['attribute' => 'data-track', 'value' => 'cta'], ['attribute' => 'data-x', 'value' => '1']], $url->customAttributes);

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame(42, $entry->targetId);
        self::assertSame(2, $entry->targetSiteId);

        self::assertInstanceOf(Email::class, $email);
        self::assertSame('hi@b.test', $email->value);
        self::assertSame('Hello there', $email->subject);

        self::assertInstanceOf(Phone::class, $phone);
        self::assertSame('+1 555 123', $phone->value);

        self::assertNull($empty->targetId, 'an emptied selector clears the target');
    }

    public function test_round_trip(): void
    {
        $links = app(Links::class);
        $original = new LinkCollection([
            $links->createLink(['type' => 'url', 'value' => 'https://a.test', 'newWindow' => true, 'rel' => 'nofollow', 'customAttributes' => [['attribute' => 'data-a', 'value' => 'b']], 'icon' => 'house']),
            $links->createLink(['type' => 'email', 'value' => 'a@b.test', 'subject' => 'Q & A']),
            $links->createLink(['type' => 'entry', 'targetId' => 9, 'targetSiteId' => 1, 'label' => 'Nine']),
        ]);

        $rows = new LinkControl($this->field)->rows($original);
        self::assertSame('mailto:a@b.test?subject=Q%20%26%20A', $rows[1]['link']['value']);
        self::assertSame('{entry:9@1:url}', $rows[2]['link']['value']);

        $back = $this->field->normalizeValue($rows);

        foreach ($original->getAll() as $i => $link) {
            self::assertSame($link->toArray(), $back->getAll()[$i]->toArray(), "link $i round-trips");
        }
    }

    public function test_empty_value_gets_one_default_row(): void
    {
        $this->field->defaultLinkType = 'email';
        $this->field->defaultNewWindow = true;

        $rows = new LinkControl($this->field)->rows(new LinkCollection);

        self::assertCount(1, $rows);
        self::assertSame('email', $rows[0]['link']['type']);
        self::assertSame('_blank', $rows[0]['link']['target']);
    }

    public function test_picker_types_follow_enabled_types(): void
    {
        $types = new LinkControl($this->field)->pickerTypes(null);

        self::assertSame(['url', 'entry', 'email', 'phone', 'custom'], array_column($types, 'id'));
        self::assertSame('element', $types[1]['kind']);
        self::assertSame('entry', $types[1]['refHandle']);
        self::assertSame(['mailto:'], $types[2]['prefixes']);
    }

    public function test_attribute_text(): void
    {
        self::assertSame([['attribute' => 'a', 'value' => ''], ['attribute' => 'b', 'value' => 'c=d']], LinkControl::textToAttributes("a\n\n b = c=d "));
        self::assertSame("a=1\nb=", LinkControl::attributesToText([['attribute' => 'a', 'value' => '1'], ['attribute' => 'b'], ['attribute' => '']]));
    }

    public function test_reference_parsing(): void
    {
        self::assertSame([12, 3], LinkControl::parseReference('{entry:12@3:url}', Entry::class));
        self::assertSame([12, null], LinkControl::parseReference('{entry:12:url}', Entry::class));
        self::assertSame([7, null], LinkControl::parseReference('7', Entry::class));
        self::assertSame([null, null], LinkControl::parseReference('nope', Entry::class));
    }
}

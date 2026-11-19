<?php

namespace justinholtweb\freelink\tests\unit;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\gql\types\input\LinkInput;
use justinholtweb\freelink\links\Email;
use justinholtweb\freelink\links\Site;
use justinholtweb\freelink\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * GraphQL/MCP/PHP input shapes through FreeLinkField::normalizeValue(), the mutation argument type
 * and the MCP input schema.
 */
class InputNormalizeTest extends TestCase
{
    private FreeLinkField $field;

    protected function setUp(): void
    {
        if (Plugin::getInstance() === null) {
            self::markTestSkipped('FreeLink plugin instance not available (no booted Craft app).');
        }

        $this->field = new FreeLinkField([
            'handle' => 'cta',
            'linkTypes' => [
                'url' => ['enabled' => true, 'sortOrder' => 0],
                'entry' => ['enabled' => true, 'sortOrder' => 1],
                'email' => ['enabled' => true, 'sortOrder' => 2],
            ],
            'showAdvanced' => true,
            'allowIcons' => true,
        ]);
    }

    public function testSingleInputObject(): void
    {
        $link = $this->field->normalizeValue([
            'type' => 'url',
            'value' => 'https://example.com',
            'label' => 'Example',
            'newWindow' => true,
            'htmlId' => 'cta-link',
            'icon' => 'arrow-right',
            'customAttributes' => [['attribute' => 'data-x', 'value' => '1']],
        ])->first();

        self::assertSame('https://example.com', $link->value);
        self::assertSame('Example', $link->label);
        self::assertTrue($link->newWindow);
        self::assertSame('cta-link', $link->id, 'htmlId maps to id');
        self::assertSame('arrow-right', $link->icon);
        self::assertSame([['attribute' => 'data-x', 'value' => '1']], $link->customAttributes);
    }

    public function testListInputWithElementTarget(): void
    {
        $value = $this->field->normalizeValue([
            ['type' => 'url', 'value' => 'https://a.test'],
            ['type' => 'entry', 'targetId' => 42, 'targetSiteId' => 1],
            ['type' => 'email', 'value' => 'a@b.test', 'subject' => 'Hi'],
        ]);

        self::assertCount(3, $value);
        $entry = $value->getAll()[1];
        self::assertInstanceOf(ElementLink::class, $entry);
        self::assertSame(42, $entry->targetId);
        self::assertSame(1, $entry->targetSiteId);
        $email = $value->getAll()[2];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Hi', $email->subject);
    }

    public function testElementIdIsAnAliasForTargetId(): void
    {
        $link = $this->field->normalizeValue(['type' => 'entry', 'elementId' => 7])->first();
        self::assertSame(7, $link->targetId);
    }

    public function testExplicitNullTargetClears(): void
    {
        $link = $this->field->normalizeValue(['type' => 'entry', 'targetId' => null])->first();
        self::assertNull($link->targetId);
        self::assertTrue($link->isEmpty());
    }

    public function testSiteLinkTakesSiteId(): void
    {
        $this->field->linkTypes['site'] = ['enabled' => true, 'sortOrder' => 3];
        $link = $this->field->normalizeValue(['type' => 'site', 'value' => '/about', 'siteId' => 1])->first();
        self::assertInstanceOf(Site::class, $link);
        self::assertSame(1, $link->siteId);
    }

    public function testIconDroppedWhenFieldDisallowsIcons(): void
    {
        $this->field->allowIcons = false;
        $link = $this->field->normalizeValue(['type' => 'url', 'value' => 'https://x.test', 'icon' => 'house'])->first();
        self::assertNull($link->icon);
    }

    public function testIconOnlyStoredWhenSet(): void
    {
        $with = $this->field->normalizeValue(['type' => 'url', 'value' => 'https://x.test', 'icon' => 'house'])->first();
        $without = $this->field->normalizeValue(['type' => 'url', 'value' => 'https://x.test'])->first();

        self::assertSame('house', $with->toArray()['icon']);
        self::assertArrayNotHasKey('icon', $without->toArray(), 'pre-5.3 JSON shape kept');
    }

    public function testMutationArgumentTypeFollowsMode(): void
    {
        $single = $this->field->getContentGqlMutationArgumentType();
        self::assertInstanceOf(InputObjectType::class, $single['type']);
        self::assertSame(LinkInput::getName(), $single['type']->name);

        $this->field->multipleLinks = true;
        $multi = $this->field->getContentGqlMutationArgumentType();
        self::assertInstanceOf(ListOfType::class, $multi['type']);
    }

    public function testInputTypeFields(): void
    {
        $fields = array_keys(LinkInput::getType()->getFields());
        foreach (['type', 'value', 'targetId', 'targetSiteId', 'siteId', 'subject', 'label', 'newWindow', 'htmlId', 'icon', 'customAttributes'] as $name) {
            self::assertContains($name, $fields);
        }
    }

    public function testInputSchemaListsOnlyEnabledTypes(): void
    {
        $schema = $this->field->getInputSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame(['url', 'entry', 'email'], $schema['properties']['type']['enum']);
        self::assertFalse($schema['additionalProperties']);
        self::assertArrayHasKey('icon', $schema['properties']);
        self::assertArrayHasKey('customAttributes', $schema['properties']);

        $this->field->multipleLinks = true;
        $this->field->maxLinks = 3;
        $this->field->showAdvanced = false;
        $multi = $this->field->getInputSchema();
        self::assertSame('array', $multi['type']);
        self::assertSame(3, $multi['maxItems']);
        self::assertArrayNotHasKey('customAttributes', $multi['items']['properties']);
    }
}

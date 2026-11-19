<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Mcp\McpOutputContext;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Helpers\UrlSafety;
use JustinHoltWeb\FreeLink\Links\Url;

/**
 * Custom attribute names are an allowlist (FreeNav's, case for case), values must be text. The same
 * helpers back validation, rendering and the MCP output.
 */
dataset('attribute names', [
    // Plain attributes on the allowlist
    ['title', true],
    ['rel', true],
    ['id', true],
    ['lang', true],
    ['role', true],
    ['itemprop', true],
    ['TITLE', true],
    ['hreflang', true],
    // aria-* and data-*
    ['aria-label', true],
    ['aria-describedby', true],
    ['data-track', true],
    ['data-nav-item', true],
    ['data-tracking_id', true],
    ['data-x', true],
    // Event handlers, URL attributes, style, class (the link has its own field)
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
    ['target', false],
    // Framework directives
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
    // Datastar attribute plugins
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
    // Names Craft's HTML helper expands, and malformed names
    ['aria', false],
    ['data', false],
    ['ng', false],
    ['data-', false],
    ['', false],
    ['data track', false],
    ['data-"x"', false],
    ['x" onclick="alert(1)', false],
]);

it('allows only safe attribute names', function (string $name, bool $safe) {
    expect(UrlSafety::isSafeAttributeName($name))->toBe($safe);
})->with('attribute names');

it('only takes text as an attribute value', function () {
    expect(UrlSafety::customAttributeValue('nav'))->toBe('nav')
        ->and(UrlSafety::customAttributeValue(3))->toBe('3')
        ->and(UrlSafety::customAttributeValue(true))->toBe('1')
        ->and(UrlSafety::customAttributeValue(null))->toBe('')
        ->and(UrlSafety::customAttributeValue(['onclick' => 'x']))->toBeNull()
        ->and(UrlSafety::customAttributeValue(new stdClass))->toBeNull()
        ->and(UrlSafety::isSafeAttributeName(['data-x']))->toBeFalse();
});

function linkWithAttributes(array $attributes): Url
{
    $link = new Url;
    $link->type = 'url';
    $link->value = 'https://example.com/';
    $link->customAttributes = $attributes;

    return $link;
}

it('refuses unsafe names and non-text values on save', function () {
    expect(linkWithAttributes([['attribute' => 'x-init', 'value' => 'alert(1)']])->validate())->toBeFalse()
        ->and(linkWithAttributes([['attribute' => 'data-on-click', 'value' => 'alert(1)']])->validate())->toBeFalse()
        ->and(linkWithAttributes([['attribute' => 'data', 'value' => ['onclick' => 'alert(1)']]])->validate())->toBeFalse()
        ->and(linkWithAttributes([['attribute' => 'title', 'value' => ['nested']]])->validate())->toBeFalse()
        ->and(linkWithAttributes([['attribute' => 'data-track', 'value' => 'nav'], ['attribute' => 'aria-label', 'value' => 'Go']])->validate())->toBeTrue();
});

it('skips stored unsafe attributes when rendering, in order', function () {
    $link = linkWithAttributes([
        ['attribute' => 'data-track', 'value' => 'nav'],
        ['attribute' => 'onmouseover', 'value' => 'alert(1)'],
        ['attribute' => 'x-on:click', 'value' => 'alert(2)'],
        ['attribute' => 'data-signals', 'value' => '{a: 1}'],
        ['attribute' => 'data', 'value' => ['onclick' => 'alert(3)']],
        'garbage',
        ['attribute' => 'title', 'value' => 'Home'],
    ]);
    $link->classes = 'btn';
    $link->rel = 'nofollow';
    $link->newWindow = true;

    expect($link->getSafeCustomAttributes())->toBe(['data-track' => 'nav', 'title' => 'Home']);

    $html = (string) $link->getLink();
    expect($html)->toContain('data-track="nav"')->toContain('title="Home"')
        ->toContain('class="btn"')->toContain('target="_blank"')->toContain('rel="nofollow noopener noreferrer"')
        ->not->toContain('alert')->not->toContain('signals');
});

it('hands only renderable attributes to the MCP output', function () {
    $field = new FreeLinkField(['handle' => 'cta', 'linkTypes' => ['url' => ['enabled' => true]], 'showAdvanced' => true]);
    $value = $field->normalizeValue(['type' => 'url', 'value' => 'https://example.com/', 'customAttributes' => [
        ['attribute' => 'data-track', 'value' => 'nav'],
        ['attribute' => 'hx-get', 'value' => '/x'],
    ]]);

    $out = $field->getMcpValue($value, new class extends Entry
    {
        public function __construct() {}
    }, new McpOutputContext(public: false));

    expect($out['customAttributes'])->toBe([['attribute' => 'data-track', 'value' => 'nav']]);
});

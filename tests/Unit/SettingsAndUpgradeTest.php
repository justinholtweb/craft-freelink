<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Tests\Unit;

use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Helpers\LegacyClassMap;
use JustinHoltWeb\FreeLink\Migrators\CraftLinkMigrator;
use JustinHoltWeb\FreeLink\Models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Plugin settings, field settings from the settings form, the upgrade's class map and the Craft
 * Link migrator's conversions.
 */
class SettingsAndUpgradeTest extends TestCase
{
    public function test_config_only_settings_never_reach_project_config(): void
    {
        $settings = new Settings;
        $settings->checkAllowedHosts = ['intranet.test'];
        $settings->checkAllowedPorts = [8080];

        $data = $settings->validationData();
        self::assertArrayNotHasKey('checkAllowedHosts', $data);
        self::assertArrayNotHasKey('checkAllowedPorts', $data);

        // And a value set on the model (a crafted settings POST) is ignored: only config/craft/freelink.php counts.
        self::assertSame([], $settings->getAllowedHosts());
        self::assertSame([80, 443], $settings->getAllowedPorts());

        config(['craft.freelink.checkAllowedHosts' => ['Intranet.Test.'], 'craft.freelink.checkAllowedPorts' => [8080, 'x', 99999]]);
        try {
            self::assertSame(['intranet.test'], $settings->getAllowedHosts());
            self::assertSame([8080], $settings->getAllowedPorts());
        } finally {
            config(['craft.freelink' => []]);
        }
    }

    public function test_svg_setting(): void
    {
        self::assertTrue(Settings::isSvgMarkup('<svg viewBox="0 0 1 1"><path d="M0 0"/></svg>'));
        self::assertTrue(Settings::isSvgMarkup('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        self::assertFalse(Settings::isSvgMarkup('/etc/passwd'));
        self::assertFalse(Settings::isSvgMarkup('<svg><script>x</script>'));
        self::assertFalse(Settings::isSvgMarkup('<div></div><svg></svg>'));

        $settings = new Settings;
        $settings->navIconSvg = '<img src=x onerror=alert(1)>';
        self::assertFalse($settings->validate());
        self::assertTrue($settings->errors()->has('navIconSvg'));

        $settings = new Settings;
        $settings->navIcon = 'link';
        $settings->navLabel = '  Links  ';
        self::assertTrue($settings->validate(), json_encode($settings->errors()->getMessages()));
        self::assertSame('Links', $settings->navLabel);

        $settings->navIcon = 'no-such-icon-anywhere';
        self::assertFalse($settings->validate());
    }

    public function test_settings_form_posts_become_link_types(): void
    {
        $field = new FreeLinkField([
            'enabledTypes' => ['email', 'url', 'entry'],
            'typeLabels' => [['handle' => 'entry', 'label' => 'Page'], ['handle' => 'url', 'label' => '']],
            'typeSources' => ['entry' => ['*', 'section:abc'], 'asset' => ['volume:x']],
            'typeConditions' => ['entry' => ['class' => 'X', 'conditionRules' => []]],
            'multipleLinks' => '1',
            'minLinks' => '-3',
        ]);

        self::assertSame(['email', 'url', 'entry'], $field->getEnabledTypeHandles());
        self::assertSame('Page', $field->linkTypes['entry']['label']);
        self::assertSame('*', $field->linkTypes['entry']['sources'], '“All” wins');
        self::assertSame(['volume:x'], $field->linkTypes['asset']['sources']);
        self::assertFalse($field->linkTypes['asset']['enabled']);
        self::assertArrayNotHasKey('selectionCondition', $field->linkTypes['entry'], 'a condition without rules is dropped');
        self::assertArrayNotHasKey('category', $field->linkTypes, 'categories are not offered on Craft 6');
        self::assertTrue($field->multipleLinks);
        self::assertSame(0, $field->minLinks);

        // Project config (no form keys) passes straight through, unavailable types included.
        $stored = new FreeLinkField(['linkTypes' => ['category' => ['enabled' => false, 'sortOrder' => 2]]]);
        self::assertSame(['category' => ['enabled' => false, 'sortOrder' => 2]], $stored->linkTypes);
    }

    public function test_legacy_class_map(): void
    {
        $old = 'justinholtweb\\freelink\\fields\\FreeLinkField';

        self::assertSame(FreeLinkField::class, LegacyClassMap::replace($old));
        self::assertSame(
            ['name' => 'CTA', 'type' => FreeLinkField::class, 'settings' => ['linkTypes' => []]],
            LegacyClassMap::replace(['name' => 'CTA', 'type' => $old, 'settings' => ['linkTypes' => []]]),
        );
        self::assertSame('{"type":"'.addslashes(FreeLinkField::class).'"}', str_replace('\\/', '/', (string) LegacyClassMap::replace(json_encode(['type' => $old]))));
        self::assertSame('craft\\fields\\Link', LegacyClassMap::replace('craft\\fields\\Link'), 'other values untouched');
        self::assertSame(LegacyClassMap::replace(['type' => FreeLinkField::class]), ['type' => FreeLinkField::class], 'second run is a no-op');
    }

    public function test_craft_link_settings_conversion(): void
    {
        $settings = new CraftLinkMigrator(null, true)->convertSettings([
            'types' => ['entry', 'url', 'email', 'category'],
            'typeSettings' => [
                'entry' => ['sources' => ['section:1']],
                'url' => ['allowRootRelativeUrls' => true],
            ],
            'showLabelField' => true,
            'advancedFields' => ['target', 'class', 'download'],
        ]);

        self::assertSame(['entry', 'url', 'email', 'custom'], array_keys($settings['linkTypes']));
        self::assertSame(['section:1'], $settings['linkTypes']['entry']['sources']);
        self::assertTrue($settings['showLabel']);
        self::assertTrue($settings['showNewWindow']);
        self::assertTrue($settings['showAdvanced']);
        self::assertSame('entry', $settings['defaultLinkType']);
    }

    public function test_craft_link_value_conversion(): void
    {
        $migrator = new CraftLinkMigrator(null, true);
        $settings = ['linkTypes' => ['url' => [], 'entry' => [], 'email' => [], 'custom' => []]];

        [$link, $target, $site] = $migrator->convertValue(['type' => 'entry', 'value' => '{entry:12@1:url}', 'label' => 'Go', 'target' => '_blank'], $settings);
        self::assertSame(['entry', null, 'Go', true], [$link['type'], $link['value'], $link['label'], $link['newWindow']]);
        self::assertSame([12, 1], [$target, $site]);

        [$link] = $migrator->convertValue(['type' => 'email', 'value' => 'mailto:a@b.test?subject=Hi%21', 'class' => 'x'], $settings);
        self::assertSame(['email', 'a@b.test', 'Hi!', 'x'], [$link['type'], $link['value'], $link['subject'], $link['classes']]);

        [$link] = $migrator->convertValue('https://example.com/a', $settings);
        self::assertSame(['url', 'https://example.com/a'], [$link['type'], $link['value']]);

        [$link] = $migrator->convertValue(['type' => 'url', 'value' => '/about'], $settings);
        self::assertSame(['custom', '/about'], [$link['type'], $link['value']], 'root-relative URLs become custom links');

        [$link] = $migrator->convertValue(['type' => 'phone', 'value' => 'tel:+15551234'], $settings);
        self::assertSame(['phone', '+15551234'], [$link['type'], $link['value']]);

        self::assertNull($migrator->convertValue(42, $settings)[0]);
    }
}

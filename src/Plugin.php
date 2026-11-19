<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons as CpIcons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\FreeLink\Cp\ReverseLookupPanel;
use JustinHoltWeb\FreeLink\Fields\FreeLinkField;
use JustinHoltWeb\FreeLink\Gql\Queries\LinksToQuery;
use JustinHoltWeb\FreeLink\Mcp\LinkTools;
use JustinHoltWeb\FreeLink\Mcp\MigrationTools;
use JustinHoltWeb\FreeLink\Models\Settings;
use JustinHoltWeb\FreeLink\Services\Checker;
use JustinHoltWeb\FreeLink\Services\LinkIndex;
use JustinHoltWeb\FreeLink\Services\Links;
use JustinHoltWeb\FreeLink\Services\Migrate;
use JustinHoltWeb\FreeLink\Services\Relations;
use JustinHoltWeb\FreeLink\Twig\FreeLinkVariable;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * FreeLink — a powerful, lightweight link field for Craft CMS.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string PERMISSION_VIEW_LINKS = 'freelink:viewLinks';

    public const string PERMISSION_CHECK_LINKS = 'freelink:checkLinks';

    /** GraphQL schema component for the `freelinkLinksTo` query. */
    public const string GQL_SCOPE_LINKS = 'freelinkLinks';

    public string $schemaVersion = '1.1.0';

    /**
     * Sites upgrade from FreeLink 5.3, so its migrations are all in place and
     * `database/migrations/Install.php` can describe the final schema.
     */
    public string $minVersionRequired = '5.3.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    protected array $fieldTypes = [
        FreeLinkField::class,
    ];

    protected array $gqlQueries = [
        LinksToQuery::class,
    ];

    protected array $commands = [
        Console\Commands\LinksReindexCommand::class,
        Console\Commands\LinksCheckCommand::class,
        Console\Commands\LinksStatusCommand::class,
        Console\Commands\MigrateFromCraftLinkCommand::class,
        Console\Commands\MigrateFromHyperCommand::class,
        Console\Commands\MigrateFromLinkitCommand::class,
        Console\Commands\MigrateFromTypedLinkCommand::class,
        Console\Commands\MigrateStatusCommand::class,
    ];

    // FreeLink 5's service properties: `Plugin::getInstance()->links->findLinksTo(…)` keeps working.
    // In new code, resolve the class from the container.

    public Links $links {
        get => $this->app->make(Links::class);
    }

    public Relations $relations {
        get => $this->app->make(Relations::class);
    }

    public LinkIndex $linkIndex {
        get => $this->app->make(LinkIndex::class);
    }

    public Checker $checker {
        get => $this->app->make(Checker::class);
    }

    public Migrate $migrate {
        get => $this->app->make(Migrate::class);
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    /**
     * The plugin's settings, typed. Falls back to defaults when the plugin isn't loaded (tests).
     */
    public static function settings(): Settings
    {
        try {
            $settings = static::getInstance()->getSettings();
        } catch (Throwable) {
            $settings = null;
        }

        return $settings instanceof Settings ? $settings : new Settings;
    }

    public function register(): void
    {
        parent::register();

        // Links memoises the type registry; the others are stateless but cheap to share.
        foreach ([Links::class, Relations::class, LinkIndex::class, Checker::class, Migrate::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(CapabilityRegistry $capabilities): void
    {
        $this->app->bind(Settings::class, fn () => self::settings());

        $this->registerTemplateVariable();
        $this->registerGql();
        $this->registerReverseLookupPanel();

        // MCP tools (the core MCP server's element tools get the field through its interfaces).
        $capabilities->register(LinkTools::class, MigrationTools::class);
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_VIEW_LINKS,
                label: t('View the Links screen and link status', category: 'freelink'),
                nested: collect([
                    new Permission(
                        key: self::PERMISSION_CHECK_LINKS,
                        label: t('Queue link checks', category: 'freelink'),
                    ),
                ]),
            ),
        ];
    }

    // Control panel
    // -------------------------------------------------------------------------

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();

        if (! $item instanceof NavItem) {
            return $item;
        }

        $this->customiseNavItem($item, self::settings());

        $user = currentUser();
        $subnav = [];

        if ($user && Gate::forUser($user)->allows(self::PERMISSION_VIEW_LINKS)) {
            $subnav[] = new NavItem()->label(t('Links', category: 'freelink'))->href('freelink/links');
            $subnav[] = new NavItem()->label(t('Broken Links', category: 'freelink'))->href('freelink/broken');
        }

        if ($user?->isAdmin()) {
            $subnav[] = new NavItem()->label(t('Migrations', category: 'freelink'))->href('freelink/migrations');

            if (Cms::config()->allowAdminChanges) {
                $subnav[] = new NavItem()->label(t('Settings', category: 'freelink'))->href('settings/plugins/freelink');
            }
        }

        return $item->subnav($subnav);
    }

    /**
     * Applies the sidebar label and icon from settings: label, then pasted SVG (sanitised every time
     * it's drawn), then an icon name.
     *
     * Craft 6's own Settings → Control Panel Navigation can rename and re-icon any item too, and an
     * override made there wins over this, because Craft applies it afterwards.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitised = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitised !== '') {
                    return $item->iconSvg($sanitised)->icon(null);
                }
            }

            if ($icon !== '' && IconSets::parse($icon) !== null) {
                // A custom set's icon (`set:name`) has to be drawn server-side.
                return $item->iconSvg(CpIcons::svg($icon))->icon(null);
            }

            if ($icon !== '' && Helpers\Icons::exists($icon, true)) {
                return $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable $e) {
            // A bad icon setting must never take the nav down; keep the default icon.
            Log::warning('FreeLink couldn’t draw the custom nav icon: '.$e->getMessage());
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $settings = self::settings();

        return Ui::make([
            Heading::make('freelink-nav', t('Control panel', category: 'freelink'))
                ->description(t('How FreeLink appears in the control panel’s sidebar. An override set in Settings → Control Panel Navigation takes precedence over these.', category: 'freelink')),
            Field::make(t('Sidebar label', category: 'freelink'), Text::make('navLabel')->placeholder($this->name)->value($settings->navLabel))
                ->instructions(t('Leave empty for “{name}”.', ['name' => $this->name], category: 'freelink')),
            Field::make(t('Sidebar icon', category: 'freelink'), IconPicker::make('navIcon')->value($settings->navIcon))
                ->instructions(t('Leave empty for FreeLink’s own icon.', category: 'freelink')),
            Field::make(t('Custom SVG icon', category: 'freelink'), Textarea::make('navIconSvg')->rows(4)->monospace()->value($settings->navIconSvg))
                ->instructions(t('Optional. Paste one `<svg>` element to use instead of the icon above. It’s sanitised every time it’s drawn; a single-colour icon that uses `currentColor` matches the rest of the sidebar.', category: 'freelink')),
            Field::make(t('Show “Linked from” on edit screens', category: 'freelink'), Lightswitch::make('showReverseLookupPanel')->value($settings->showReverseLookupPanel))
                ->instructions(t('Lists the FreeLink links that point at the element being edited, for users with the “View the Links screen” permission.', category: 'freelink')),

            Heading::make('freelink-checks', t('Link checks', category: 'freelink'))
                ->description(t('Element links are checked against the database whenever the Links screen is shown. URL links are fetched over HTTP only from the queue or the console, and only URLs saved in FreeLink fields are ever fetched.', category: 'freelink')),
            Field::make(t('Check URL links over HTTP', category: 'freelink'), Lightswitch::make('enableExternalChecks')->value($settings->enableExternalChecks))
                ->instructions(t('Off: URL links are never fetched.', category: 'freelink')),
            Field::make(t('Recheck after (hours)', category: 'freelink'), Number::make('recheckAfter')->min(1)->max(24 * 365)->value($settings->recheckAfter))
                ->instructions(t('A URL checked more recently than this is skipped, unless a check is forced.', category: 'freelink')),
            Field::make(t('Delay between requests to one host (ms)', category: 'freelink'), Number::make('hostDelay')->min(0)->max(60000)->value($settings->hostDelay)),
            Field::make(t('URLs per check run', category: 'freelink'), Number::make('maxChecksPerRun')->min(1)->max(10000)->value($settings->maxChecksPerRun)),
            Heading::make('freelink-checks-config', t('Private hosts and ports', category: 'freelink'))
                ->description(t('Hosts allowed to resolve to private addresses (`checkAllowedHosts`) and the ports a checked URL may use (`checkAllowedPorts`) can only be set in `config/craft/freelink.php`, so nobody with control panel access can widen what the checker reaches.', category: 'freelink')),
        ]);
    }

    /**
     * The URL of the Links screen with the given filters.
     *
     * @param  array<string, mixed>  $params
     */
    public static function linksUrl(array $params = []): string
    {
        return Url::cpUrl('freelink/links', array_filter($params, fn ($v) => $v !== null && $v !== ''));
    }

    private function registerTemplateVariable(): void
    {
        // `craft.freelink` (documented since 1.0) and the `freelink` global (Twig) / `$freelink` (Blade).
        // Macros are bound to the CraftVariable, so the closure must not use `$this`.
        CraftVariable::macro('freelink', fn () => app(FreeLinkVariable::class));

        Event::listen(TemplateGlobalsResolving::class, function (TemplateGlobalsResolving $event): void {
            $event->globals['freelink'] ??= $this->app->make(FreeLinkVariable::class);
        });
    }

    private function registerGql(): void
    {
        Event::listen(GqlSchemaComponentsResolving::class, function (GqlSchemaComponentsResolving $event): void {
            $event->queries[t('FreeLink', category: 'freelink')] = [
                self::GQL_SCOPE_LINKS.':read' => [
                    'label' => t('Query reverse link lookups (freelinkLinksTo)', category: 'freelink'),
                ],
            ];
        });
    }

    private function registerReverseLookupPanel(): void
    {
        Event::listen(ElementMetadataResolving::class, function (ElementMetadataResolving $event): void {
            if (! self::settings()->showReverseLookupPanel || ! request()->isCpRequest()) {
                return;
            }

            $element = $event->element;

            $event->metadata[t('Linked from', category: 'freelink')] = function () use ($element): string|false {
                try {
                    return ReverseLookupPanel::html($element) ?? false;
                } catch (Throwable $e) {
                    Log::warning('FreeLink reverse lookup panel failed: '.$e->getMessage());

                    return false;
                }
            };
        });
    }
}

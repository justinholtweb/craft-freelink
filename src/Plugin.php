<?php

namespace justinholtweb\freelink;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\gql\queries\LinksToQuery;
use justinholtweb\freelink\gql\types\LinkReferenceType;
use justinholtweb\freelink\helpers\Icons;
use justinholtweb\freelink\models\Settings;
use justinholtweb\freelink\services\Checker;
use justinholtweb\freelink\services\LinkIndex;
use justinholtweb\freelink\services\Links;
use justinholtweb\freelink\services\Migrate;
use justinholtweb\freelink\services\Relations;
use justinholtweb\freelink\twig\Extension;
use justinholtweb\freelink\twig\FreeLinkVariable;
use justinholtweb\freelink\web\ReverseLookupPanel;
use yii\base\Event;

/**
 * FreeLink - A powerful, lightweight link field for Craft CMS 5.
 *
 * @property-read Links $links
 * @property-read Relations $relations
 * @property-read Migrate $migrate
 * @property-read LinkIndex $linkIndex
 * @property-read Checker $checker
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public const PERMISSION_VIEW_LINKS = 'freelink:viewLinks';
    public const PERMISSION_CHECK_LINKS = 'freelink:checkLinks';

    /** GraphQL schema component for the `freelinkLinksTo` query. */
    public const GQL_SCOPE_LINKS = 'freelinkLinks';

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'links' => Links::class,
                'relations' => Relations::class,
                'migrate' => Migrate::class,
                'linkIndex' => LinkIndex::class,
                'checker' => Checker::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerFieldType();
        $this->_registerTwig();
        $this->_registerGraphQl();
        $this->_registerPermissions();
        $this->_registerMcp();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
            $this->_registerSidebarPanel();
        }
    }

    // region Settings

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('freelink/_cp/settings', [
            'settings' => $this->getSettings(),
            'configOnly' => Settings::CONFIG_ONLY,
        ]);
    }

    // endregion

    // region CP nav

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $settings = $this->getSettings();

        $label = trim((string)$settings->navLabel);
        if ($label !== '') {
            $item['label'] = $label;
        }

        $svg = trim((string)$settings->navIconSvg);
        $icon = trim((string)$settings->navIcon);

        if ($svg !== '' && Settings::isSvgMarkup($svg)) {
            try {
                // Sanitised on every render, never trusted as stored.
                $item['icon'] = Html::svg($svg, true, true, true);
            } catch (\Throwable) {
                // Leave the default icon.
            }
        } elseif ($icon !== '' && Icons::exists($icon, true)) {
            $item['icon'] = $icon;
        }

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission(self::PERMISSION_VIEW_LINKS)) {
            $subnav['links'] = ['label' => Craft::t('freelink', 'Links'), 'url' => 'freelink/links'];
            $subnav['broken'] = ['label' => Craft::t('freelink', 'Broken Links'), 'url' => 'freelink/broken'];
        }

        if ($user->getIsAdmin()) {
            $subnav['migrations'] = ['label' => Craft::t('freelink', 'Migrations'), 'url' => 'freelink/migrations'];

            if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                $subnav['settings'] = ['label' => Craft::t('app', 'Settings'), 'url' => 'settings/plugins/freelink'];
            }
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    // endregion

    private function _registerFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = FreeLinkField::class;
            },
        );
    }

    private function _registerTwig(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());

        // `craft.freelink` — documented since 1.0, but only the `freelink` global existed before 5.3.
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('freelink', FreeLinkVariable::class);
            },
        );
    }

    private function _registerGraphQl(): void
    {
        // Field output/input types are registered lazily by the field type.

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = LinkReferenceType::class;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, LinksToQuery::getQueries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[Craft::t('freelink', 'FreeLink')] = [
                self::GQL_SCOPE_LINKS . ':read' => [
                    'label' => Craft::t('freelink', 'Query reverse link lookups (freelinkLinksTo)'),
                ],
            ];
        });
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('freelink', 'FreeLink'),
                    'permissions' => [
                        self::PERMISSION_VIEW_LINKS => [
                            'label' => Craft::t('freelink', 'View the Links screen and link status'),
                            'nested' => [
                                self::PERMISSION_CHECK_LINKS => [
                                    'label' => Craft::t('freelink', 'Queue link checks'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['freelink'] = 'freelink/links/section-index';
                $event->rules['freelink/links'] = 'freelink/links/index';
                $event->rules['freelink/broken'] = 'freelink/links/broken';
                $event->rules['freelink/migrations'] = 'freelink/migrations/index';
                // Pre-5.3 URL of the template-routed migration page.
                $event->rules['freelink/migrate'] = 'freelink/migrations/legacy-redirect';
                $event->rules['freelink/migrate/index'] = 'freelink/migrations/legacy-redirect';
            },
        );
    }

    private function _registerSidebarPanel(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                if ($event->static || !$this->getSettings()->showReverseLookupPanel) {
                    return;
                }

                try {
                    $event->html .= ReverseLookupPanel::html($event->sender);
                } catch (\Throwable $e) {
                    Craft::warning('FreeLink reverse lookup panel failed: ' . $e->getMessage(), __METHOD__);
                }
            },
        );
    }

    /**
     * MCP tools (Craft 5.12+). Skipped on older Craft, where the MCP server doesn't exist.
     */
    private function _registerMcp(): void
    {
        if (!class_exists(\craft\services\Mcp::class) || !class_exists(\Mcp\Capability\Attribute\McpTool::class)) {
            return;
        }

        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = mcp\LinkTools::class;
                $event->types[] = mcp\MigrationTools::class;
            },
        );
    }

    /**
     * The URL of the Links screen with the given filters.
     *
     * @param array<string, mixed> $params
     */
    public static function linksUrl(array $params = []): string
    {
        return UrlHelper::cpUrl('freelink/links', array_filter($params, fn($v) => $v !== null && $v !== ''));
    }
}

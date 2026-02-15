<?php

namespace justinholtweb\freelink;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use justinholtweb\freelink\fields\FreeLinkField;
use justinholtweb\freelink\services\Links;
use justinholtweb\freelink\services\Migrate;
use justinholtweb\freelink\services\Relations;
use justinholtweb\freelink\twig\Extension;
use yii\base\Event;

/**
 * FreeLink - A powerful, lightweight link field for Craft CMS 5.
 *
 * @property Links $links
 * @property Relations $relations
 * @property Migrate $migrate
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'links' => Links::class,
                'relations' => Relations::class,
                'migrate' => Migrate::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerFieldType();
        $this->_registerConsoleControllers();
        $this->_registerTwigExtension();
        $this->_registerGraphQl();
    }

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

    private function _registerConsoleControllers(): void
    {
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'justinholtweb\\freelink\\console\\controllers';
        }
    }

    private function _registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    private function _registerGraphQl(): void
    {
        // GraphQL type registration is handled lazily by the field type's getContentGqlType()
    }
}

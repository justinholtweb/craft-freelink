<?php

declare(strict_types=1);

use CraftCms\Cms\Translation\I18N;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use JustinHoltWeb\FreeLink\Helpers\Icons;
use JustinHoltWeb\FreeLink\Services\Links;
use Psr\Log\NullLogger;

/**
 * Test bootstrap.
 *
 * The unit suite runs without booting Craft: the link models, the field's normalising and schemas,
 * the CP control mapping, the SSRF guard, IP ranges, the checker's redirect logic (scripted
 * transport) and the index rows are plain PHP over plain data. Everything that needs a database or
 * a request is checked live in the plugin-testing-craft6 harness (see tests/README.md).
 */

require_once __DIR__.'/../vendor/autoload.php';

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

// Just enough container for the facades, validators and translations the pure code touches.
$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container); // @phpstan-ignore argument.type
$translator = new Translator(new ArrayLoader, 'en');
$container->instance('translator', $translator);
$container->instance(Illuminate\Contracts\Validation\Factory::class, new Factory($translator, $container));
$container->alias(Illuminate\Contracts\Validation\Factory::class, 'validator');
$container->instance('events', new Dispatcher($container));
$container->instance('config', new Repository([]));
$container->instance('log', new NullLogger);
$container->singleton(Links::class);

// `t()` → I18N::translate(): format ICU messages without Craft's message sources.
$container->instance(I18N::class, new class
{
    /** @param array<string, mixed> $parameters */
    public function translate(mixed $message, array $parameters = [], ?string $category = null, ?string $locale = null): string
    {
        $message = (string) $message;

        if ($parameters === []) {
            return $message;
        }

        return class_exists(MessageFormatter::class)
            ? (string) MessageFormatter::formatMessage('en', $message, $parameters)
            : strtr($message, array_combine(array_map(fn ($k) => '{'.$k.'}', array_keys($parameters)), array_map('strval', $parameters)));
    }
});

// Craft's bundled icons, straight from the package (no Craft app to ask).
Icons::$basePath = __DIR__.'/../vendor/craftcms/cms-assets/resources/icons';

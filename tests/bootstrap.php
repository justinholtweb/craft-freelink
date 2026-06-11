<?php

/**
 * PHPUnit bootstrap for the FreeLink plugin.
 *
 * The link models extend yii\base\Model and lean on a few Craft helper classes
 * (some of which read Craft::$app), so the tests need a Composer autoloader that
 * exposes the Craft/Yii class tree as well as the plugin's own classes.
 *
 * Autoloader resolution order:
 *   1. FREELINK_AUTOLOAD env var — point this at a host project's
 *      vendor/autoload.php when the plugin is symlinked into a dev install
 *      (e.g. a DDEV test site).
 *   2. The plugin's own vendor/ (composer install run inside the plugin).
 *   3. The standard location when the plugin is installed as a dependency.
 *
 * When a full Craft install is reachable, a Craft console application is booted
 * so that helpers depending on Craft::$app work. If no Craft project is present
 * the suite still runs against a minimal Yii alias — only the tests that need a
 * booted app are affected.
 */

$autoload = null;

$candidates = array_filter([
    getenv('FREELINK_AUTOLOAD') ?: null,
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
]);

foreach ($candidates as $path) {
    if (is_file($path)) {
        $autoload = $path;
        require $path;
        break;
    }
}

if ($autoload === null) {
    fwrite(
        STDERR,
        "Unable to locate a Composer autoloader for the FreeLink test suite.\n" .
        "Set the FREELINK_AUTOLOAD environment variable to a vendor/autoload.php\n" .
        "that includes craftcms/cms, or run `composer install` inside the plugin.\n",
    );
    exit(1);
}

$vendorDir = dirname($autoload);

// Boot a Craft console application when one is reachable, so helpers that read
// Craft::$app (Template::raw, element queries, etc.) work. Craft's bootstrap
// also defines the `Yii`/`Craft` class aliases.
$consoleBootstrap = $vendorDir . '/craftcms/cms/bootstrap/console.php';

if (is_file($consoleBootstrap) && (!class_exists('Craft', false) || Craft::$app === null)) {
    $basePath = getenv('CRAFT_BASE_PATH') ?: dirname($vendorDir);

    if (!defined('CRAFT_BASE_PATH')) {
        define('CRAFT_BASE_PATH', $basePath);
    }
    if (!defined('CRAFT_VENDOR_PATH')) {
        define('CRAFT_VENDOR_PATH', $vendorDir);
    }

    // Load the project's .env so the app can find its DB/config settings.
    if (class_exists(Dotenv\Dotenv::class) && is_file($basePath . '/.env')) {
        Dotenv\Dotenv::createUnsafeMutable($basePath)->safeLoad();
    }

    try {
        // Returns a configured craft\console\Application and sets Craft::$app.
        require $consoleBootstrap;
    } catch (\Throwable $e) {
        fwrite(STDERR, "Notice: could not boot Craft for tests ({$e->getMessage()}).\n");
        fwrite(STDERR, "Tests that depend on a booted Craft application will be skipped.\n");
    }
}

// Fallback for a standalone run with no Craft install: yii2's Yii.php is a
// bootstrap file (not classmap-autoloaded), so register the `Yii` alias here so
// Event/BaseObject constructors work.
if (!class_exists('Yii', false)) {
    $yii = $vendorDir . '/yiisoft/yii2/Yii.php';
    if (is_file($yii)) {
        require $yii;
    }
}

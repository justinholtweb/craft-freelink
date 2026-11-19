<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Helpers;

use CraftCms\Cms\Support\CmsAssets;
use CraftCms\Cms\Support\Html;
use Throwable;

/**
 * Craft's bundled icon set (Font Awesome + Craft's custom icons), as FreeLink uses it for the CP nav
 * icon and per-link icons.
 *
 * Craft's set includes Font Awesome Pro icons, licensed for the control panel. Per-link icons render
 * on the front end, so fields offer only the free icons unless `includeProIcons` is on.
 */
final class Icons
{
    /** Families checked for an icon's SVG, in the order Craft's own `Icons::resolveIconFamily()` uses. */
    private const array FAMILIES = ['custom-icons', 'solid', 'brands', 'regular'];

    /** @var array<array-key, array<string, mixed>>|null */
    private static ?array $index = null;

    /** Overrides the icon directory (tests). */
    public static ?string $basePath = null;

    public static function isValidName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 64 && (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name);
    }

    /**
     * Whether an icon exists in Craft's set (and, when `$allowPro` is false, is a free icon).
     */
    public static function exists(string $name, bool $allowPro = false): bool
    {
        if (! self::isValidName($name) || self::path($name) === null) {
            return false;
        }

        return $allowPro || ! self::isPro($name);
    }

    /**
     * Whether a Font Awesome icon is a Pro icon. Craft's custom icons and unknown names count as Pro,
     * so an icon whose licence can't be confirmed is never offered as free.
     */
    public static function isPro(string $name): bool
    {
        $index = self::index();

        if (! isset($index[$name])) {
            return true;
        }

        return (bool) ($index[$name]['pro'] ?? true);
    }

    /**
     * The SVG file for an icon name, or null.
     */
    public static function path(string $name): ?string
    {
        if (! self::isValidName($name)) {
            return null;
        }

        foreach (self::FAMILIES as $family) {
            $path = self::basePath()."/$family/$name.svg";

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * An icon's SVG markup with the given attributes added, or null.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function svg(?string $name, array $attributes = []): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $path = self::path($name);

        if ($path === null) {
            return null;
        }

        $svg = (string) file_get_contents($path);

        // Drop the XML declaration/comments (Font Awesome's licence comment) so the markup can be inlined.
        $svg = trim(preg_replace('/<\?xml.*?\?>|<!--.*?-->/s', '', $svg) ?? $svg);

        $attributes += [
            'aria-hidden' => 'true',
            'focusable' => 'false',
            'fill' => 'currentColor',
        ];

        try {
            return Html::modifyTagAttributes($svg, $attributes);
        } catch (Throwable) {
            return $svg;
        }
    }

    private static function basePath(): string
    {
        return self::$basePath ?? CmsAssets::resourcesPath('icons');
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    private static function index(): array
    {
        if (self::$index === null) {
            $path = self::basePath().'/index.php';
            $index = is_file($path) ? require $path : [];
            self::$index = is_array($index) ? $index : [];
        }

        return self::$index;
    }
}

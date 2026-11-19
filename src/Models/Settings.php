<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Models;

use CraftCms\Cms\Plugin\PluginSettings;
use DOMDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use JustinHoltWeb\FreeLink\Helpers\Icons;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * FreeLink plugin settings.
 *
 * Nothing here is `required`: a fresh install must be able to save any one setting on its own.
 * Text settings are nullable, because Laravel turns a blank field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** The CP sidebar label. Empty uses the plugin name. */
    public ?string $navLabel = null;

    /** The CP sidebar icon: an icon name from Craft's icon picker (e.g. `link`). */
    public ?string $navIcon = null;

    /**
     * Raw SVG for the CP sidebar icon. Wins over `navIcon`. Stored as entered and sanitised every
     * time it is drawn.
     */
    public ?string $navIconSvg = null;

    /** Show the "Linked from" panel on element edit screens. */
    public bool $showReverseLookupPanel = true;

    /** Allow URL links to be checked over HTTP. Element link status never fetches anything. */
    public bool $enableExternalChecks = true;

    /** Hours before a checked URL is due again. */
    public int $recheckAfter = 24;

    /** Minimum gap between two requests to the same host, in milliseconds. */
    public int $hostDelay = 1000;

    /** The most URLs one check run fetches. */
    public int $maxChecksPerRun = 500;

    /**
     * Hosts allowed to resolve to private/reserved addresses (intranet links, local testing).
     * Config file only (`config/craft/freelink.php`) — see getAllowedHosts().
     *
     * @var string[]
     */
    public array $checkAllowedHosts = [];

    /**
     * Ports a checked URL may use. Config file only — see getAllowedPorts().
     *
     * @var int[]
     */
    public array $checkAllowedPorts = [80, 443];

    /** Settings that may only come from config/craft/freelink.php. */
    public const array CONFIG_ONLY = ['checkAllowedHosts', 'checkAllowedPorts'];

    public const int MAX_SVG_BYTES = 51200;

    /**
     * Config-only settings never go to project config, so a CP save can neither persist nor widen
     * them.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function validationData(): array
    {
        return Arr::except(parent::validationData(), self::CONFIG_ONLY);
    }

    #[Override]
    public function getRules(): array
    {
        return [
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'navIconSvg' => ['nullable', 'string', 'max:'.self::MAX_SVG_BYTES],
            'recheckAfter' => ['integer', 'min:1', 'max:'.(24 * 365)],
            'hostDelay' => ['integer', 'min:0', 'max:60000'],
            'maxChecksPerRun' => ['integer', 'min:1', 'max:10000'],
            'showReverseLookupPanel' => ['boolean'],
            'enableExternalChecks' => ['boolean'],
        ];
    }

    #[Override]
    public function getMessages(): array
    {
        return [
            'navIcon.regex' => t('Choose an icon from the icon picker.', category: 'freelink'),
        ];
    }

    #[Override]
    public function attributeLabels(): array
    {
        return [
            'navLabel' => t('Sidebar label', category: 'freelink'),
            'navIcon' => t('Sidebar icon', category: 'freelink'),
            'navIconSvg' => t('Custom SVG icon', category: 'freelink'),
            'recheckAfter' => t('Recheck after', category: 'freelink'),
            'hostDelay' => t('Delay between requests to one host', category: 'freelink'),
            'maxChecksPerRun' => t('URLs per check run', category: 'freelink'),
        ];
    }

    /**
     * The checks a rule string can't express: the icon must exist in Craft's set, the SVG must be
     * one `<svg>` element.
     */
    #[Override]
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        foreach (['navLabel', 'navIcon', 'navIconSvg'] as $attribute) {
            $value = $this->$attribute;
            $this->$attribute = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        parent::validate($attributeNames, $clearErrors, $throw);

        if ($this->navIcon !== null && ! $this->errors()->has('navIcon') && ! Icons::exists($this->navIcon, true)) {
            $this->errors()->add('navIcon', t('Choose an icon from the icon picker.', category: 'freelink'));
        }

        if ($this->navIconSvg !== null && ! $this->errors()->has('navIconSvg') && ! self::isSvgMarkup($this->navIconSvg)) {
            $this->errors()->add('navIconSvg', t('Paste a single <svg> element.', category: 'freelink'));
        }

        return $this->errors()->isEmpty();
    }

    /**
     * Whether the text is one `<svg>` element (optionally after an XML declaration / comments).
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (! preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg)) {
            return false;
        }

        if (! preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // No network, no entity expansion.
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }

    /**
     * Ports a checked URL may use: from `config/craft/freelink.php` only, never the stored settings.
     *
     * @return int[]
     */
    public function getAllowedPorts(): array
    {
        $configured = self::configOnly('checkAllowedPorts', [80, 443]);
        $ports = array_values(array_filter(array_map('intval', $configured), fn (int $p) => $p > 0 && $p < 65536));

        return $ports ?: [80, 443];
    }

    /**
     * Hosts allowed to resolve to private addresses: from `config/craft/freelink.php` only.
     *
     * @return string[]
     */
    public function getAllowedHosts(): array
    {
        return array_values(array_filter(array_map(
            fn ($h) => rtrim(strtolower(trim(is_scalar($h) ? (string) $h : '')), '.'),
            self::configOnly('checkAllowedHosts', []),
        )));
    }

    /**
     * @param  array<int, mixed>  $default
     * @return array<int, mixed>
     */
    private static function configOnly(string $key, array $default): array
    {
        try {
            $value = Config::get("craft.freelink.$key", $default);
        } catch (Throwable) {
            $value = $default;
        }

        return is_array($value) ? array_values($value) : $default;
    }
}

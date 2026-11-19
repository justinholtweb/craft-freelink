<?php

namespace justinholtweb\freelink\models;

use Craft;
use craft\base\Model;
use justinholtweb\freelink\helpers\Icons;

/**
 * FreeLink plugin settings.
 *
 * Nothing here is `required`: a fresh install must be able to save any one setting on its own.
 */
class Settings extends Model
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
     * Config file only (`config/freelink.php`) — never taken from a CP form.
     *
     * @var string[]
     */
    public array $checkAllowedHosts = [];

    /**
     * Ports a checked URL may use. Config file only.
     *
     * @var int[]
     */
    public array $checkAllowedPorts = [80, 443];

    /** Settings that may only come from config/freelink.php. */
    public const CONFIG_ONLY = ['checkAllowedHosts', 'checkAllowedPorts'];

    public const MAX_SVG_BYTES = 51200;

    /**
     * Config-only settings are left out of the settings saved to project config, so a CP save can
     * neither persist nor widen them. config/freelink.php sets them on every request.
     *
     * @return array<string|int, mixed>
     */
    public function fields(): array
    {
        $fields = parent::fields();

        foreach (self::CONFIG_ONLY as $key) {
            unset($fields[$key]);
        }

        return $fields;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['navLabel', 'navIcon', 'navIconSvg'], 'trim'],
            [['navLabel'], 'string', 'max' => 64],
            [['navIcon'], 'match', 'pattern' => '/^[a-z0-9\-]+$/', 'message' => Craft::t('freelink', 'Choose an icon from the icon picker.')],
            [['navIcon'], 'validateNavIcon', 'skipOnEmpty' => true],
            [['navIconSvg'], 'string', 'max' => self::MAX_SVG_BYTES],
            [['navIconSvg'], 'validateSvg', 'skipOnEmpty' => true],
            [['recheckAfter'], 'integer', 'min' => 1, 'max' => 24 * 365],
            [['hostDelay'], 'integer', 'min' => 0, 'max' => 60000],
            [['maxChecksPerRun'], 'integer', 'min' => 1, 'max' => 10000],
            [['showReverseLookupPanel', 'enableExternalChecks'], 'boolean'],
        ];
    }

    public function validateNavIcon(string $attribute): void
    {
        if (!Icons::exists((string)$this->$attribute, true)) {
            $this->addError($attribute, Craft::t('freelink', 'Choose an icon from the icon picker.'));
        }
    }

    public function validateSvg(string $attribute): void
    {
        if (!self::isSvgMarkup((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('freelink', 'Paste a single <svg> element.'));
        }
    }

    /**
     * Whether the text is one `<svg>` element (optionally after an XML declaration / comments).
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (!preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg)) {
            return false;
        }

        if (!preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // No network, no entity expansion.
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }

    /**
     * @return int[]
     */
    public function getAllowedPorts(): array
    {
        $ports = array_values(array_filter(array_map('intval', $this->checkAllowedPorts), fn(int $p) => $p > 0 && $p < 65536));

        return $ports ?: [80, 443];
    }

    /**
     * @return string[]
     */
    public function getAllowedHosts(): array
    {
        return array_values(array_filter(array_map(
            fn($h) => rtrim(strtolower(trim((string)$h)), '.'),
            $this->checkAllowedHosts,
        )));
    }
}

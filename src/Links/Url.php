<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\Link;
use Override;

use function CraftCms\Cms\t;

class Url extends Link
{
    /**
     * Yii 2's URL pattern, which FreeLink 5 validated with: http(s), a dotted host, an optional port.
     * Kept so the same values pass on Craft 6.
     */
    public const string PATTERN = '/^(http|https):\/\/(([A-Z0-9][A-Z0-9_-]*)(\.[A-Z0-9][A-Z0-9_-]*)+)(?::\d{1,5})?(?:$|[?\/#])/i';

    #[Override]
    public static function displayName(): string
    {
        return t('URL', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'url';
    }

    #[Override]
    public static function inputPlaceholder(): string
    {
        return 'https://';
    }

    /**
     * A URL without a scheme is checked as https, and saved with it when it passes (as Yii's URL
     * validator with `defaultScheme` did in FreeLink 5).
     */
    #[Override]
    protected function checks(?array $attributes): void
    {
        parent::checks($attributes);

        if (! self::checking($attributes, 'value') || $this->value === null || $this->value === '') {
            return;
        }

        $candidate = str_contains($this->value, '://') ? $this->value : 'https://'.$this->value;

        if (strlen($candidate) > 2000 || ! preg_match(self::PATTERN, $candidate)) {
            $this->addError('value', t('{attribute} is not a valid URL.', ['attribute' => t('Value', category: 'freelink')], category: 'freelink'));

            return;
        }

        $this->value = $candidate;
    }
}

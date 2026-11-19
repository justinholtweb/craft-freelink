<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\Link;
use Override;

use function CraftCms\Cms\t;

class Phone extends Link
{
    public const string PATTERN = '/^[\d\s\-\+\(\)\.x]+$/';

    #[Override]
    public static function displayName(): string
    {
        return t('Phone', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'phone';
    }

    #[Override]
    public static function inputPlaceholder(): string
    {
        return '+1 (555) 123-4567';
    }

    #[Override]
    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        // Everything except digits, + and x is formatting.
        return 'tel:'.preg_replace('/[^\d+x]/', '', $this->value);
    }

    #[Override]
    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'value' => ['nullable', 'string', 'regex:'.self::PATTERN],
        ]);
    }

    #[Override]
    public function getMessages(): array
    {
        return array_merge(parent::getMessages(), [
            'value.regex' => t('{attribute} is invalid.', ['attribute' => t('Value', category: 'freelink')], category: 'freelink'),
        ]);
    }
}

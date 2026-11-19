<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use JustinHoltWeb\FreeLink\Base\Link;
use Override;

use function CraftCms\Cms\t;

class Email extends Link
{
    /** Yii 2's e-mail pattern, which FreeLink 5 validated with. */
    public const string PATTERN = '/^[a-zA-Z0-9!#$%&\'*+\\/=?^_`{|}~-]+(?:\.[a-zA-Z0-9!#$%&\'*+\\/=?^_`{|}~-]+)*@(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/';

    public ?string $subject = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Email', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'email';
    }

    #[Override]
    public static function inputPlaceholder(): string
    {
        return 'hello@example.com';
    }

    #[Override]
    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        $url = 'mailto:'.$this->value;

        if (! empty($this->subject)) {
            $url .= '?subject='.rawurlencode($this->subject);
        }

        return $url;
    }

    #[Override]
    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    #[Override]
    protected function checks(?array $attributes): void
    {
        parent::checks($attributes);

        if (self::checking($attributes, 'value') && $this->value !== null && $this->value !== '' && ! preg_match(self::PATTERN, $this->value)) {
            $this->addError('value', t('{attribute} is not a valid email address.', ['attribute' => t('Value', category: 'freelink')], category: 'freelink'));
        }
    }

    /**
     * @param  string[]  $fields
     * @param  string[]  $expand
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(array $fields = [], array $expand = [], bool $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        $data['subject'] = $this->subject;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toApiArray(): array
    {
        $data = parent::toApiArray();
        $data['subject'] = $this->subject;

        return $data;
    }
}

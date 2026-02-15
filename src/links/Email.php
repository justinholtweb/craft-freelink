<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Email extends Link
{
    public static function displayName(): string
    {
        return 'Email';
    }

    public static function handle(): string
    {
        return 'email';
    }

    public static function inputPlaceholder(): string
    {
        return 'hello@example.com';
    }

    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        return 'mailto:' . $this->value;
    }

    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['value', 'email'];

        return $rules;
    }
}

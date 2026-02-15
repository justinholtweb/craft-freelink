<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Sms extends Link
{
    public static function displayName(): string
    {
        return 'SMS';
    }

    public static function handle(): string
    {
        return 'sms';
    }

    public static function inputPlaceholder(): string
    {
        return '+1 (555) 123-4567';
    }

    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        $tel = preg_replace('/[^\d+x]/', '', $this->value);

        return 'sms:' . $tel;
    }

    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['value', 'match', 'pattern' => '/^[\d\s\-\+\(\)\.x]+$/'];

        return $rules;
    }
}

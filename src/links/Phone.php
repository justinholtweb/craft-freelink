<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Phone extends Link
{
    public static function displayName(): string
    {
        return 'Phone';
    }

    public static function handle(): string
    {
        return 'phone';
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

        // Strip everything except digits, +, and x for tel: URI
        $tel = preg_replace('/[^\d+x]/', '', $this->value);

        return 'tel:' . $tel;
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

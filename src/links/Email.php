<?php

namespace justinholtweb\freelink\links;

use justinholtweb\freelink\base\Link;

class Email extends Link
{
    /**
     * Optional subject line, encoded into the mailto: URL as ?subject=.
     */
    public ?string $subject = null;

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

        $url = 'mailto:' . $this->value;

        if (!empty($this->subject)) {
            $url .= '?subject=' . rawurlencode($this->subject);
        }

        return $url;
    }

    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['value', 'email'];
        $rules[] = ['subject', 'string'];

        return $rules;
    }

    /**
     * @param string[] $fields
     * @param string[] $expand
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        $data['subject'] = $this->subject;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $data = parent::toApiArray();
        $data['subject'] = $this->subject;

        return $data;
    }
}

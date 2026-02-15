<?php

namespace justinholtweb\freelink\links;

use Craft;
use justinholtweb\freelink\base\Link;

class Site extends Link
{
    public ?int $siteId = null;

    public static function displayName(): string
    {
        return 'Site';
    }

    public static function handle(): string
    {
        return 'site';
    }

    public static function inputPlaceholder(): string
    {
        return '/relative-path';
    }

    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        $site = null;
        if ($this->siteId) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
        }

        if (!$site) {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        return rtrim($site->getBaseUrl(), '/') . '/' . ltrim($this->value, '/');
    }

    public function getText(): ?string
    {
        return $this->label ?? $this->value;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        $data = parent::toArray($fields, $expand, $recursive);
        $data['siteId'] = $this->siteId;

        return $data;
    }
}

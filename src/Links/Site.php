<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Links;

use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\FreeLink\Base\Link;
use Override;
use Throwable;

use function CraftCms\Cms\t;

class Site extends Link
{
    public ?int $siteId = null;

    #[Override]
    public static function displayName(): string
    {
        return t('Site', category: 'freelink');
    }

    #[Override]
    public static function handle(): string
    {
        return 'site';
    }

    #[Override]
    public static function inputPlaceholder(): string
    {
        return '/relative-path';
    }

    #[Override]
    protected function getBaseUrl(): ?string
    {
        if (empty($this->value)) {
            return null;
        }

        try {
            $site = ($this->siteId ? Sites::getSiteById($this->siteId) : null) ?? Sites::getCurrentSite();
            $baseUrl = $site->getBaseUrl();
        } catch (Throwable) {
            return null;
        }

        if ($baseUrl === null) {
            return null;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($this->value, '/');
    }

    #[Override]
    public function getText(): ?string
    {
        return $this->label ?? $this->value;
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
        $data['siteId'] = $this->siteId;

        return $data;
    }
}

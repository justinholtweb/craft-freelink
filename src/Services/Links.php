<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use JustinHoltWeb\FreeLink\Base\ElementLink;
use JustinHoltWeb\FreeLink\Base\Link;
use JustinHoltWeb\FreeLink\Events\RegisterLinkTypes;
use JustinHoltWeb\FreeLink\Links\Asset;
use JustinHoltWeb\FreeLink\Links\Category;
use JustinHoltWeb\FreeLink\Links\Custom;
use JustinHoltWeb\FreeLink\Links\Email;
use JustinHoltWeb\FreeLink\Links\Entry;
use JustinHoltWeb\FreeLink\Links\Phone;
use JustinHoltWeb\FreeLink\Links\Product;
use JustinHoltWeb\FreeLink\Links\Site;
use JustinHoltWeb\FreeLink\Links\Sms;
use JustinHoltWeb\FreeLink\Links\Url;
use JustinHoltWeb\FreeLink\Links\User;
use JustinHoltWeb\FreeLink\Links\Variant;
use JustinHoltWeb\FreeLink\Models\LinkReference;

/**
 * Link type registry and the public reverse-lookup API.
 */
class Links
{
    /** The built-in link types, in the order the field settings list them. */
    public const array BUILT_IN = [
        Url::class,
        Email::class,
        Phone::class,
        Sms::class,
        Custom::class,
        Site::class,
        Entry::class,
        Asset::class,
        Category::class,
        User::class,
        Product::class,
        Variant::class,
    ];

    /** @var array<string, class-string<Link>>|null handle => class */
    private ?array $types = null;

    /**
     * Returns all registered link type classes, keyed by handle.
     *
     * @return array<string, class-string<Link>>
     */
    public function getAllTypes(): array
    {
        return $this->types ??= $this->loadTypes();
    }

    /**
     * Returns the link types that can be offered for new links: element types whose element class
     * is installed (no categories on Craft 6; no Commerce types until Commerce 6), and every simple
     * type.
     *
     * @return array<string, class-string<Link>>
     */
    public function getAvailableTypes(): array
    {
        return array_filter($this->getAllTypes(), function (string $class): bool {
            if (method_exists($class, 'isAvailable')) {
                return (bool) $class::isAvailable();
            }

            return true;
        });
    }

    /**
     * Returns a link type class by handle.
     *
     * @return class-string<Link>|null
     */
    public function getTypeByHandle(string $handle): ?string
    {
        return $this->getAllTypes()[$handle] ?? null;
    }

    /**
     * Creates a Link model from an array of data.
     *
     * @param  array<string, mixed>  $data
     */
    public function createLink(array $data): ?Link
    {
        $type = $data['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = $this->getTypeByHandle($type);

        if (! $class) {
            return null;
        }

        $link = new $class;
        $link->type = $type;

        $link->value = self::str($data['value'] ?? null);
        $link->label = self::str($data['label'] ?? null);
        $link->newWindow = filter_var($data['newWindow'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $link->ariaLabel = self::str($data['ariaLabel'] ?? null);
        $link->title = self::str($data['title'] ?? null);
        $link->urlSuffix = self::str($data['urlSuffix'] ?? null);
        $link->classes = self::str($data['classes'] ?? null);
        $link->id = self::str($data['id'] ?? null);
        $link->rel = self::str($data['rel'] ?? null);
        $link->customAttributes = is_array($data['customAttributes'] ?? null) ? array_values(array_filter($data['customAttributes'], 'is_array')) : [];
        $icon = $data['icon'] ?? null;
        $link->icon = is_string($icon) && trim($icon) !== '' ? trim($icon) : null;

        if ($link instanceof ElementLink) {
            $link->targetId = isset($data['targetId']) && is_numeric($data['targetId']) && (int) $data['targetId'] > 0 ? (int) $data['targetId'] : null;
            $link->targetSiteId = isset($data['targetSiteId']) && is_numeric($data['targetSiteId']) && (int) $data['targetSiteId'] > 0 ? (int) $data['targetSiteId'] : null;

            if (isset($data['sources']) && (is_string($data['sources']) || is_array($data['sources']))) {
                $link->sources = $data['sources'];
            }
        }

        if ($link instanceof Site && isset($data['siteId']) && is_numeric($data['siteId'])) {
            $link->siteId = (int) $data['siteId'];
        }

        if ($link instanceof Email && isset($data['subject'])) {
            $link->subject = self::str($data['subject']);
        }

        return $link;
    }

    /**
     * Links in live content that point at an element (reverse lookup).
     *
     * Options: `field` (handle), `type` (link type handle), `site`/`siteId` (the source's site),
     * `limit` (default 100, max 100), `offset`, `status` (see LinkIndex::createQuery()).
     *
     * Usage: `Plugin::getInstance()->links->findLinksTo($entry, ['field' => 'cta'])`
     *
     * @param  array<string, mixed>  $options
     * @return LinkReference[]
     */
    public function findLinksTo(ElementInterface $element, array $options = []): array
    {
        $targetId = $element->getCanonicalId() ?: $element->id;

        if (! $targetId) {
            return [];
        }

        $criteria = [
            'targetId' => $targetId,
            'field' => $options['field'] ?? null,
            'type' => $options['type'] ?? null,
            'siteId' => $options['siteId'] ?? $options['site'] ?? null,
            'status' => $options['status'] ?? 'all',
        ];

        $result = app(LinkIndex::class)->find(
            $criteria,
            (int) ($options['limit'] ?? LinkIndex::MAX_LIMIT),
            (int) ($options['offset'] ?? 0),
        );

        return $result['references'];
    }

    /**
     * @return array<string, class-string<Link>>
     */
    private function loadTypes(): array
    {
        $types = [];

        foreach (self::BUILT_IN as $class) {
            $types[$class::handle()] = $class;
        }

        event($event = new RegisterLinkTypes);

        foreach ($event->types as $class) {
            if (is_subclass_of($class, Link::class) && $class::handle() !== '') {
                $types[$class::handle()] = $class;
            }
        }

        return $types;
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value) && ! method_exists($value, '__toString')) {
            return null;
        }

        return (string) $value;
    }
}

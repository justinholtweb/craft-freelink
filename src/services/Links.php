<?php

namespace justinholtweb\freelink\services;

use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\events\RegisterLinkTypesEvent;
use justinholtweb\freelink\links\Asset;
use justinholtweb\freelink\links\Category;
use justinholtweb\freelink\links\Custom;
use justinholtweb\freelink\links\Email;
use justinholtweb\freelink\links\Entry;
use justinholtweb\freelink\links\Phone;
use justinholtweb\freelink\links\Product;
use justinholtweb\freelink\links\Site;
use justinholtweb\freelink\links\Sms;
use justinholtweb\freelink\links\Url;
use justinholtweb\freelink\links\User;
use justinholtweb\freelink\links\Variant;
use yii\base\Component;

/**
 * Link type registry service.
 */
class Links extends Component
{
    public const EVENT_REGISTER_LINK_TYPES = 'registerLinkTypes';

    /** @var array<string, class-string<Link>> handle => class */
    private array $_types = [];
    private bool $_typesLoaded = false;

    /**
     * Returns all registered link type classes, keyed by handle.
     *
     * @return array<string, class-string<Link>>
     */
    public function getAllTypes(): array
    {
        if (!$this->_typesLoaded) {
            $this->_loadTypes();
        }

        return $this->_types;
    }

    /**
     * Returns the available link types (filtering out unavailable ones like Commerce types).
     *
     * @return array<string, class-string<Link>>
     */
    public function getAvailableTypes(): array
    {
        $types = $this->getAllTypes();

        return array_filter($types, function(string $class) {
            if (method_exists($class, 'isAvailable')) {
                return $class::isAvailable();
            }

            return true;
        });
    }

    /**
     * Returns a link type class by handle.
     */
    public function getTypeByHandle(string $handle): ?string
    {
        $types = $this->getAllTypes();

        return $types[$handle] ?? null;
    }

    /**
     * Creates a Link model from an array of data.
     */
    public function createLink(array $data): ?Link
    {
        $type = $data['type'] ?? null;

        if (!$type) {
            return null;
        }

        $class = $this->getTypeByHandle($type);

        if (!$class) {
            return null;
        }

        $link = new $class();
        $link->type = $type;

        // Set common properties
        $link->value = $data['value'] ?? null;
        $link->label = $data['label'] ?? null;
        $link->newWindow = (bool)($data['newWindow'] ?? false);
        $link->ariaLabel = $data['ariaLabel'] ?? null;
        $link->title = $data['title'] ?? null;
        $link->urlSuffix = $data['urlSuffix'] ?? null;
        $link->classes = $data['classes'] ?? null;
        $link->id = $data['id'] ?? null;
        $link->rel = $data['rel'] ?? null;
        $link->customAttributes = $data['customAttributes'] ?? [];

        // Element link specific
        if ($link instanceof ElementLink) {
            $link->targetId = isset($data['targetId']) ? (int)$data['targetId'] : null;
            $link->targetSiteId = isset($data['targetSiteId']) ? (int)$data['targetSiteId'] : null;

            if (isset($data['sources'])) {
                $link->sources = $data['sources'];
            }
        }

        // Site link specific
        if ($link instanceof \justinholtweb\freelink\links\Site && isset($data['siteId'])) {
            $link->siteId = (int)$data['siteId'];
        }

        return $link;
    }

    private function _loadTypes(): void
    {
        $this->_typesLoaded = true;

        // Built-in types
        $builtIn = [
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

        foreach ($builtIn as $class) {
            $this->_types[$class::handle()] = $class;
        }

        // Fire event for custom types
        $event = new RegisterLinkTypesEvent(['types' => []]);
        $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);

        foreach ($event->types as $class) {
            if (is_subclass_of($class, Link::class)) {
                $this->_types[$class::handle()] = $class;
            }
        }
    }
}

<?php

namespace justinholtweb\freelink\models;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use JsonSerializable;
use Stringable;
use justinholtweb\freelink\base\Link;
use Twig\Markup;

/**
 * A collection of Link objects returned as the field value.
 *
 * Implements a transparent proxy pattern: in single-link mode,
 * magic methods delegate to the first link. In multi-link mode,
 * the collection is iterable.
 */
class LinkCollection implements IteratorAggregate, Countable, ArrayAccess, Stringable, JsonSerializable
{
    /** @var Link[] */
    private array $links;

    /**
     * @param Link[] $links
     */
    public function __construct(array $links = [])
    {
        $this->links = array_values($links);
    }

    /**
     * Returns all links.
     *
     * @return Link[]
     */
    public function getAll(): array
    {
        return $this->links;
    }

    /**
     * Alias for getAll(), used in Twig iteration: `entry.myField.all`.
     *
     * @return Link[]
     */
    public function all(): array
    {
        return $this->links;
    }

    /**
     * Returns the first link, or null.
     */
    public function getFirst(): ?Link
    {
        return $this->links[0] ?? null;
    }

    /**
     * Alias for getFirst().
     */
    public function one(): ?Link
    {
        return $this->getFirst();
    }

    /**
     * Alias for getFirst().
     */
    public function first(): ?Link
    {
        return $this->getFirst();
    }

    /**
     * Whether the collection has no non-empty links.
     */
    public function isEmpty(): bool
    {
        foreach ($this->links as $link) {
            if (!$link->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a filtered collection.
     */
    public function filter(callable $fn): self
    {
        return new self(array_filter($this->links, $fn));
    }

    // region Proxy methods — delegate to first link for single-link usage

    public function getUrl(): ?string
    {
        return $this->getFirst()?->getUrl();
    }

    public function getText(): ?string
    {
        return $this->getFirst()?->getText();
    }

    public function getLink(array $attributes = []): ?Markup
    {
        return $this->getFirst()?->getLink($attributes);
    }

    public function getElement(): ?\craft\base\ElementInterface
    {
        return $this->getFirst()?->getElement();
    }

    public function getTarget(): ?string
    {
        return $this->getFirst()?->getTarget();
    }

    // endregion

    // region Magic property access — proxy to first link

    public function __get(string $name): mixed
    {
        $first = $this->getFirst();

        if ($first === null) {
            return null;
        }

        // Check for getter method on Link
        $getter = 'get' . ucfirst($name);
        if (method_exists($first, $getter)) {
            return $first->$getter();
        }

        // Direct property access
        if (property_exists($first, $name)) {
            return $first->$name;
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        $first = $this->getFirst();

        if ($first === null) {
            return false;
        }

        $getter = 'get' . ucfirst($name);
        if (method_exists($first, $getter)) {
            return $first->$getter() !== null;
        }

        return isset($first->$name);
    }

    public function __call(string $name, array $arguments): mixed
    {
        $first = $this->getFirst();

        if ($first === null) {
            return null;
        }

        return $first->$name(...$arguments);
    }

    // endregion

    // region IteratorAggregate

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->links);
    }

    // endregion

    // region Countable

    public function count(): int
    {
        return count($this->links);
    }

    // endregion

    // region ArrayAccess

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->links[$offset]);
    }

    public function offsetGet(mixed $offset): ?Link
    {
        return $this->links[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->links[] = $value;
        } else {
            $this->links[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->links[$offset]);
        $this->links = array_values($this->links);
    }

    // endregion

    // region JsonSerializable

    public function jsonSerialize(): mixed
    {
        return array_map(fn(Link $link) => $link->toApiArray(), $this->links);
    }

    // endregion

    // region Stringable

    public function __toString(): string
    {
        return $this->getFirst()?->getUrl() ?? '';
    }

    // endregion
}

<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Events;

use JustinHoltWeb\FreeLink\Base\Link;

/**
 * Dispatched once per request when FreeLink builds its link type registry. Add classes extending
 * `Link` (or `ElementLink`) to `$types`:
 *
 * ```php
 * Event::listen(RegisterLinkTypes::class, fn (RegisterLinkTypes $e) => $e->types[] = MyLink::class);
 * ```
 *
 * FreeLink 5's `Links::EVENT_REGISTER_LINK_TYPES` Yii event.
 */
class RegisterLinkTypes
{
    /**
     * @param  list<class-string<Link>|string>  $types
     */
    public function __construct(
        public array $types = [],
    ) {}
}

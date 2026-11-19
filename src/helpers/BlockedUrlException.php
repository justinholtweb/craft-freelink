<?php

namespace justinholtweb\freelink\helpers;

/**
 * Thrown by SsrfGuard when a URL may not be requested.
 *
 * `$blocked` is false for refusals that aren't a safety decision (a host that doesn't resolve),
 * which are stored as `error` rather than `blocked`.
 */
class BlockedUrlException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $blocked = true)
    {
        parent::__construct($message);
    }
}

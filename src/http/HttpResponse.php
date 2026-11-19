<?php

namespace justinholtweb\freelink\http;

/**
 * The parts of a response the link checker looks at. The body is never kept.
 */
class HttpResponse
{
    public function __construct(
        public readonly int $code = 0,
        public readonly ?string $location = null,
        public readonly ?string $error = null,
    ) {
    }
}

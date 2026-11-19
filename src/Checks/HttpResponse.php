<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Checks;

/**
 * The parts of a response the link checker looks at. The body is never kept.
 */
class HttpResponse
{
    public function __construct(
        public readonly int $code = 0,
        public readonly ?string $location = null,
        public readonly ?string $error = null,
        /** The address the request actually connected to (curl's primary IP), when known. */
        public readonly ?string $primaryIp = null,
    ) {}
}

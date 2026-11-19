<?php

namespace justinholtweb\freelink\twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension providing the `freelink` global (also registered as `craft.freelink`).
 */
class Extension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return [
            'freelink' => new FreeLinkVariable(),
        ];
    }
}

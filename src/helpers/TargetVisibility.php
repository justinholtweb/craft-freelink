<?php

namespace justinholtweb\freelink\helpers;

use Craft;
use justinholtweb\freelink\base\ElementLink;
use justinholtweb\freelink\base\Link;
use justinholtweb\freelink\gql\GqlScope;

/**
 * Whether whoever is looking — a GraphQL schema, or the signed-in user (CP, MCP) — may see an element
 * link's target. Used where FreeLink itself renders a target's title or URL to an API or the CP, so a
 * link saved to something the viewer can't see (for example in an older draft) reveals nothing.
 *
 * Front-end templates are the site's own and are not filtered.
 */
class TargetVisibility
{
    public static function canSee(Link $link): bool
    {
        if (!$link instanceof ElementLink || !$link->targetId) {
            return true;
        }

        if (GqlScope::isActive()) {
            return GqlScope::canQuery((int)$link->targetId);
        }

        try {
            $user = Craft::$app->getUser()->getIdentity();
        } catch (\Throwable) {
            $user = null;
        }

        if (!$user) {
            return true;
        }

        $target = $link->getElement();

        return $target !== null && Craft::$app->getElements()->canView($target, $user);
    }
}

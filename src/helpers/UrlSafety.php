<?php

namespace justinholtweb\freelink\helpers;

/**
 * What a link may render: no script-running URL schemes, no event-handler attributes.
 *
 * Checked twice — when a link is validated, so an editor sees why it was refused, and when it is
 * rendered, so links saved before 5.1.2 (or written by a migration or the API, which never
 * validate) can't run script either.
 */
class UrlSafety
{
    /** Schemes a browser will execute rather than navigate to. */
    private const UNSAFE_SCHEMES = ['javascript', 'vbscript', 'data'];

    /**
     * Whether a URL would run script when followed.
     *
     * Browsers drop ASCII whitespace and control characters from a URL's scheme — `java\tscript:`
     * is `javascript:` — so they are removed before the scheme is read. `javascript:void(0)`,
     * `javascript:void 0` and `javascript:;` are allowed: they run nothing, and they are what the
     * Custom type is often for.
     */
    public static function isUnsafeUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $clean = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if (!preg_match('/^([a-z][a-z0-9+.\-]*):(.*)$/is', $clean, $m)) {
            return false;
        }

        $scheme = strtolower($m[1]);

        if (!in_array($scheme, self::UNSAFE_SCHEMES, true)) {
            return false;
        }

        return !($scheme === 'javascript' && preg_match('/^(void\(?0\)?;?|;?)$/i', $m[2]));
    }

    /**
     * Whether an editor-supplied attribute name is safe to put on an `<a>`.
     *
     * A plain attribute name, never an event handler (`on…`), and never one that sets a URL the
     * scheme check above would not see.
     */
    public static function isSafeAttributeName(mixed $name): bool
    {
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_:.\-]*$/i', $name)) {
            return false;
        }

        $lower = strtolower($name);

        return !str_starts_with($lower, 'on')
            && !in_array($lower, ['href', 'xlink:href', 'src', 'srcdoc', 'formaction', 'action'], true);
    }
}

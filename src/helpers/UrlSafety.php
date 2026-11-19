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

    /** Plain attributes a custom attribute may set. */
    public const SAFE_PLAIN_ATTRIBUTES = [
        'title', 'rel', 'id', 'lang', 'dir', 'role', 'tabindex', 'accesskey', 'translate',
        'hreflang', 'download', 'type', 'itemprop', 'itemscope', 'itemtype', 'itemid', 'referrerpolicy',
    ];

    /**
     * Whether an editor-supplied attribute name is safe to put on an `<a>`.
     *
     * The same allowlist as FreeNav 5.2.1: `aria-*`, `data-*`, and a handful of plain attributes
     * (`title`, `rel`, `id`, `lang`, `role`, `itemprop`…). Event handlers, URL attributes, `style`,
     * `class` (FreeLink has its own CSS Classes setting) and front-end framework directives that run
     * code from an attribute's value are not on it: Alpine (`x-*`, `@…`, `:…`), htmx (`hx-*`,
     * `data-hx-*`), Vue (`v-*`), Angular (`ng-*`, `data-ng-*`) and Datastar's script-running `data-*`
     * attributes. A site whose front end runs another framework that executes `data-*` attributes
     * should treat its editors as trusted.
     */
    public static function isSafeAttributeName(mixed $name): bool
    {
        if (!is_string($name)) {
            return false;
        }

        $lower = strtolower($name);

        if (in_array($lower, self::SAFE_PLAIN_ATTRIBUTES, true)) {
            return true;
        }

        if (preg_match('/^aria-[a-z]+$/', $lower)) {
            return true;
        }

        if (!preg_match('/^data-[a-z0-9][a-z0-9_-]*$/', $lower)) {
            return false;
        }

        return !preg_match('/^data-(hx-|ng-|x-|v-|on|bind|attr|signals|json-signals|effect|computed|init|ref|indicator|persist|text|show|class|style|model|ignore|scroll-into-view|view-transition|replace-url|query-string|custom-validity|preserve-attr|star-|animate|match-media|store|intersects|teleport)/', $lower);
    }

    /**
     * A custom attribute's value as a string, or null when it isn't a scalar (Craft's HTML helper
     * expands an array value into sub-attributes whose names are never checked).
     */
    public static function customAttributeValue(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * The custom attributes that may be kept, in order, as `[{attribute, value}]`. Accepts the
     * `{attribute, value}` shape FreeLink stores and the `{key, value}` shape other plugins use.
     *
     * @param mixed $attributes
     * @return list<array{attribute: string, value: string}>
     */
    public static function safeCustomAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }

        $safe = [];

        foreach ($attributes as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $name = $attr['attribute'] ?? $attr['key'] ?? $attr['name'] ?? null;
            $name = is_string($name) ? trim($name) : null;
            $value = self::customAttributeValue($attr['value'] ?? null);

            if ($name !== null && $name !== '' && $value !== null && self::isSafeAttributeName($name)) {
                $safe[] = ['attribute' => $name, 'value' => $value];
            }
        }

        return $safe;
    }
}

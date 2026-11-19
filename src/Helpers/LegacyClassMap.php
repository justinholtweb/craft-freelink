<?php

declare(strict_types=1);

namespace JustinHoltWeb\FreeLink\Helpers;

use JustinHoltWeb\FreeLink\Fields\FreeLinkField;

/**
 * FreeLink 5's class names that Craft stores, mapped to FreeLink 6's.
 *
 * FreeLink 5 stored only its field type (`fields.type` and `fields.<uid>.type` in project config);
 * link types live in FreeLink's own JSON by handle, not class. A FreeLink 5 class name is a whole
 * stored value, so values are replaced only on an exact match.
 */
final class LegacyClassMap
{
    public const array MAP = [
        'justinholtweb\\freelink\\fields\\FreeLinkField' => FreeLinkField::class,
    ];

    public static function className(string $value): string
    {
        return self::MAP[$value] ?? $value;
    }

    /**
     * Replaces FreeLink 5 class names anywhere in a stored value: plain strings, array keys, nested
     * arrays (project config, including its `__assoc__` pairs) and JSON held in a string.
     */
    public static function replace(mixed $value): mixed
    {
        if (is_string($value)) {
            if (isset(self::MAP[$value])) {
                return self::MAP[$value];
            }

            $trimmed = ltrim($value);

            if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
                if (! str_contains($value, 'justinholtweb\\\\freelink') || ! json_validate($value)) {
                    return $value;
                }

                $decoded = json_decode($value, true);
                $updated = self::replace($decoded);

                return $updated === $decoded ? $value : (string) json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }

            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $updated = [];

        foreach ($value as $key => $item) {
            $updated[is_string($key) ? self::className($key) : $key] = self::replace($item);
        }

        return $updated;
    }
}

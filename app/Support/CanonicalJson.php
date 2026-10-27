<?php

namespace App\Support;

/**
 * Deterministic JSON encoding: object keys are sorted recursively so two
 * payloads that differ only in key order produce the same hash.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function depth(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }

        $deepest = 0;

        foreach ($value as $item) {
            $deepest = max($deepest, self::depth($item));
        }

        return $deepest + 1;
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::normalize(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

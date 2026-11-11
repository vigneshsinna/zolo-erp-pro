<?php

declare(strict_types=1);

namespace App\Ai\Services;

use InvalidArgumentException;
use JsonException;

/** Stable canonical JSON for SHA-256 proposal integrity and signature inputs. */
final class CanonicalActionPayload
{
    public static function json(array $payload): string
    {
        try {
            return json_encode(self::sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Action payload contains invalid JSON values.', previous: $e);
        }
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', self::json($payload));
    }

    private static function sort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('Unsupported action payload key.');
            }

            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::sort($value);
        }

        // Monetary amounts and quantities must be exact-decimal strings.
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Floating-point or object values are not allowed in action proposals.');
        }

        return $value;
    }
}

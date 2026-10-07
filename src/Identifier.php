<?php

declare(strict_types=1);

namespace Kipchak\Identity;

final class Identifier
{
    public const int MAX_LENGTH = 256;

    /**
     * A usable consumer or tenant id from whatever a resolver found, or null. Strings and integers only: an
     * array or object means the resolver points at the wrong thing, and billing it as "Array" would be worse
     * than not attributing it.
     */
    public static function normalise(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return $value;
    }
}

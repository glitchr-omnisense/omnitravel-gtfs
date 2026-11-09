<?php

namespace Omnitravel\Gtfs;

/** A store's column, read as the type it holds: PDO gives strings, ints or nulls depending on the driver. */
final class Cast
{
    public static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

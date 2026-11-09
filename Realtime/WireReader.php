<?php

namespace Omnitravel\Gtfs\Realtime;

use Omnitravel\Exception\ProviderException;

/**
 * Protocol Buffers' wire format, read by hand: the few messages of
 * GTFS-Realtime need varints, 32- and 64-bit numbers and length-delimited
 * fields, nothing else - no generated classes, no protobuf extension.
 *
 * fields() gives each field of a message as [number, wire type, value]: an
 * int for a varint, the raw bytes for a length-delimited field (a string,
 * a nested message), 4 or 8 raw bytes for fixed32 and fixed64 (read them
 * with float32(), double()).
 */
final class WireReader
{
    public const VARINT = 0;
    public const FIXED64 = 1;
    public const BYTES = 2;
    public const FIXED32 = 5;

    /** @return \Generator<int, array{0: int, 1: int, 2: int|string}> */
    public static function fields(string $message): \Generator
    {
        $offset = 0;
        $length = \strlen($message);
        while ($offset < $length) {
            $key = self::varint($message, $offset);
            $number = $key >> 3;
            $type = $key & 7;
            yield match ($type) {
                self::VARINT => [$number, $type, self::varint($message, $offset)],
                self::FIXED64 => [$number, $type, self::take($message, $offset, 8)],
                self::BYTES => [$number, $type, self::take($message, $offset, self::varint($message, $offset))],
                self::FIXED32 => [$number, $type, self::take($message, $offset, 4)],
                default => throw new ProviderException(\sprintf('Not a GTFS-Realtime message: wire type %d at byte %d.', $type, $offset)),
            };
        }
    }

    /** A varint, as a signed 64-bit integer (an int32's negative values come as ten bytes). */
    public static function varint(string $message, int &$offset): int
    {
        $value = 0;
        for ($shift = 0; $shift < 64; $shift += 7) {
            if ($offset >= \strlen($message)) {
                throw new ProviderException('Not a GTFS-Realtime message: a number is cut short.');
            }
            $byte = \ord($message[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            if ($byte < 0x80) {
                return $value;
            }
        }
        throw new ProviderException('Not a GTFS-Realtime message: a number runs over ten bytes.');
    }

    /** An int32 field's value: a varint's low 32 bits, signed. */
    public static function int32(int $varint): int
    {
        $value = $varint & 0xFFFFFFFF;

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public static function float32(string $bytes): float
    {
        $value = unpack('g', $bytes);

        return \is_array($value) && \is_float($value[1] ?? null) ? $value[1] : 0.0;
    }

    public static function double(string $bytes): float
    {
        $value = unpack('e', $bytes);

        return \is_array($value) && \is_float($value[1] ?? null) ? $value[1] : 0.0;
    }

    private static function take(string $message, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > \strlen($message)) {
            throw new ProviderException('Not a GTFS-Realtime message: a field runs past its end.');
        }
        $bytes = substr($message, $offset, $length);
        $offset += $length;

        return $bytes;
    }
}

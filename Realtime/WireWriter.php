<?php

namespace Omnitravel\Gtfs\Realtime;

/**
 * Protocol Buffers' wire format, written by hand: what a test needs to build
 * a GTFS-Realtime feed (WireReader's other half), and an application to
 * serve one.
 */
final class WireWriter
{
    public static function varint(int $number, int $value): string
    {
        return self::key($number, WireReader::VARINT).self::encode($value);
    }

    public static function bytes(int $number, string $bytes): string
    {
        return self::key($number, WireReader::BYTES).self::encode(\strlen($bytes)).$bytes;
    }

    public static function float32(int $number, float $value): string
    {
        return self::key($number, WireReader::FIXED32).pack('g', $value);
    }

    /** An int32: its negative values as ten bytes, as protobuf writes them. */
    public static function int32(int $number, int $value): string
    {
        return self::varint($number, $value);
    }

    private static function key(int $number, int $type): string
    {
        return self::encode($number << 3 | $type);
    }

    private static function encode(int $value): string
    {
        $bytes = '';
        do {
            $byte = $value & 0x7F;
            // A logical shift: a negative int64 takes its ten bytes.
            $value = ($value >> 7) & 0x01FFFFFFFFFFFFFF;
            $bytes .= \chr($value ? $byte | 0x80 : $byte);
        } while ($value);

        return $bytes;
    }
}

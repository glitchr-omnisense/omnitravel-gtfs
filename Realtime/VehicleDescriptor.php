<?php

namespace Omnitravel\Gtfs\Realtime;

/** GTFS-Realtime's VehicleDescriptor. */
final readonly class VehicleDescriptor
{
    public function __construct(public ?string $id = null, public ?string $label = null, public ?string $licensePlate = null)
    {
    }

    public static function parse(string $message): self
    {
        $v = [null, null, null];
        foreach (WireReader::fields($message) as [$number, , $value]) {
            if ($number >= 1 && $number <= 3) {
                $v[$number - 1] = (string) $value;
            }
        }

        return new self(...$v);
    }
}

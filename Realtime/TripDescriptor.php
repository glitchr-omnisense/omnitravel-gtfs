<?php

namespace Omnitravel\Gtfs\Realtime;

/** GTFS-Realtime's TripDescriptor. */
final readonly class TripDescriptor
{
    public const SCHEDULED = 0;
    public const ADDED = 1;
    public const CANCELED = 3;
    public const DELETED = 7;

    public function __construct(
        public ?string $tripId = null,
        public ?string $routeId = null,
        public ?string $startDate = null,
        public ?string $startTime = null,
        public int $relationship = self::SCHEDULED,
        public ?int $directionId = null,
    ) {
    }

    public static function parse(string $message): self
    {
        $v = [null, null, null, null, self::SCHEDULED, null];
        foreach (WireReader::fields($message) as [$number, , $value]) {
            match ($number) {
                1 => $v[0] = (string) $value,
                2 => $v[3] = (string) $value,
                3 => $v[2] = (string) $value,
                4 => $v[4] = (int) $value,
                5 => $v[1] = (string) $value,
                6 => $v[5] = (int) $value,
                default => null,
            };
        }

        return new self($v[0], $v[1], $v[2], $v[3], $v[4], $v[5]);
    }

    public function cancelled(): bool
    {
        return \in_array($this->relationship, [self::CANCELED, self::DELETED], true);
    }

    public function added(): bool
    {
        return self::ADDED === $this->relationship;
    }
}

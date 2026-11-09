<?php

namespace Omnitravel\Gtfs\Realtime;

/**
 * A GTFS-Realtime feed (FeedMessage), read from its Protocol Buffers bytes:
 * its header's time, its trip updates and its vehicle positions. Alerts are
 * skipped.
 */
final readonly class FeedMessage
{
    /**
     * @param list<TripUpdate>    $tripUpdates
     * @param list<VehicleUpdate> $vehicles
     */
    public function __construct(public ?int $timestamp = null, public array $tripUpdates = [], public array $vehicles = [], public ?string $version = null)
    {
    }

    public static function parse(string $bytes): self
    {
        $timestamp = $version = null;
        $trips = $vehicles = [];
        foreach (WireReader::fields($bytes) as [$number, , $value]) {
            if (1 === $number) {
                foreach (WireReader::fields((string) $value) as [$n, , $v]) {
                    if (1 === $n) {
                        $version = (string) $v;
                    } elseif (3 === $n) {
                        $timestamp = (int) $v;
                    }
                }
            } elseif (2 === $number) {
                $deleted = false;
                $trip = $vehicle = null;
                foreach (WireReader::fields((string) $value) as [$n, , $v]) {
                    match ($n) {
                        2 => $deleted = 0 !== (int) $v,
                        3 => $trip = TripUpdate::parse((string) $v),
                        4 => $vehicle = VehicleUpdate::parse((string) $v),
                        default => null,
                    };
                }
                if (!$deleted && null !== $trip) {
                    $trips[] = $trip;
                }
                if (!$deleted && null !== $vehicle) {
                    $vehicles[] = $vehicle;
                }
            }
        }

        return new self($timestamp, $trips, $vehicles, $version);
    }

    /** @return array<string, list<TripUpdate>> the trip updates by trip identifier */
    public function byTrip(): array
    {
        $byTrip = [];
        foreach ($this->tripUpdates as $update) {
            if (null !== $update->tripId) {
                $byTrip[$update->tripId][] = $update;
            }
        }

        return $byTrip;
    }
}

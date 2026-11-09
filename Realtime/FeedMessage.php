<?php

namespace Omnitravel\Gtfs\Realtime;

use Omnitravel\Gtfs\ServiceAlert;

/**
 * A GTFS-Realtime feed (FeedMessage), read from its Protocol Buffers bytes:
 * its header's time, its trip updates, its vehicle positions and its
 * service alerts.
 */
final readonly class FeedMessage
{
    /**
     * @param list<TripUpdate>    $tripUpdates
     * @param list<VehicleUpdate> $vehicles
     * @param list<ServiceAlert>  $alerts
     */
    public function __construct(public ?int $timestamp = null, public array $tripUpdates = [], public array $vehicles = [], public ?string $version = null, public array $alerts = [])
    {
    }

    public static function parse(string $bytes): self
    {
        $timestamp = $version = null;
        $trips = $vehicles = $alerts = [];
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
                $trip = $vehicle = $alert = null;
                $id = '';
                foreach (WireReader::fields((string) $value) as [$n, , $v]) {
                    match ($n) {
                        1 => $id = (string) $v,
                        2 => $deleted = 0 !== (int) $v,
                        3 => $trip = TripUpdate::parse((string) $v),
                        4 => $vehicle = VehicleUpdate::parse((string) $v),
                        5 => $alert = (string) $v,
                        default => null,
                    };
                }
                if (!$deleted && null !== $trip) {
                    $trips[] = $trip;
                }
                if (!$deleted && null !== $vehicle) {
                    $vehicles[] = $vehicle;
                }
                if (!$deleted && null !== $alert) {
                    $alerts[] = Alert::parse($id, $alert);
                }
            }
        }

        return new self($timestamp, $trips, $vehicles, $version, $alerts);
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

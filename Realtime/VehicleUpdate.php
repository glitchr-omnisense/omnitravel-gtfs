<?php

namespace Omnitravel\Gtfs\Realtime;

use Omnitravel\Model\VehicleStatus;

/** GTFS-Realtime's VehiclePosition. */
final readonly class VehicleUpdate
{
    /** @param ?float $speed metres per second */
    public function __construct(
        public ?string $vehicle,
        public ?float $latitude,
        public ?float $longitude,
        public TripDescriptor $trip,
        public ?string $label = null,
        public ?float $bearing = null,
        public ?float $speed = null,
        public ?int $timestamp = null,
        public ?VehicleStatus $status = null,
        public ?string $stopId = null,
        public ?int $sequence = null,
    ) {
    }

    public static function parse(string $message): self
    {
        $trip = new TripDescriptor();
        $vehicle = new VehicleDescriptor();
        $lat = $lon = $bearing = $speed = null;
        $timestamp = $status = $stopId = $sequence = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            switch ($number) {
                case 1: $trip = TripDescriptor::parse((string) $value);
                    break;
                case 2:
                    foreach (WireReader::fields((string) $value) as [$n, $type, $v]) {
                        $float = WireReader::FIXED32 === $type ? WireReader::float32((string) $v) : null;
                        match ($n) {
                            1 => $lat = $float,
                            2 => $lon = $float,
                            3 => $bearing = $float,
                            5 => $speed = $float,
                            default => null,
                        };
                    }
                    break;
                case 3: $sequence = (int) $value;
                    break;
                case 4: $status = match ((int) $value) {
                    0 => VehicleStatus::Incoming,
                    1 => VehicleStatus::Stopped,
                    default => VehicleStatus::InTransit,
                };
                    break;
                case 5: $timestamp = (int) $value;
                    break;
                case 7: $stopId = (string) $value;
                    break;
                case 8: $vehicle = VehicleDescriptor::parse((string) $value);
                    break;
            }
        }

        return new self($vehicle->id ?? $vehicle->label, $lat, $lon, $trip, $vehicle->label, $bearing, $speed, $timestamp, $status, $stopId, $sequence);
    }
}

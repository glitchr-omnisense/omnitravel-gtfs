<?php

namespace Omnitravel\Gtfs\Siri;

/**
 * A vehicle's call at a stop as SIRI's StopMonitoring announces it (a
 * MonitoredStopVisit), its references translated back to the GTFS feed's
 * identifiers.
 */
final readonly class Visit
{
    /**
     * @param ?string $line        the GTFS route identifier (null when the LineRef does not fit the template)
     * @param ?string $stop        the GTFS stop identifier of the platform called at
     * @param ?int    $expected    the expected departure (or arrival, for a terminus) as a timestamp
     * @param ?int    $aimed       the timetable's, when SIRI gives it
     * @param ?string $journey     SIRI's DatedVehicleJourneyRef
     */
    public function __construct(
        public ?string $line,
        public ?string $stop,
        public string $destination,
        public ?int $expected,
        public ?int $aimed = null,
        public bool $atStop = false,
        public bool $cancelled = false,
        public ?string $platform = null,
        public ?string $direction = null,
        public ?string $journey = null,
        public ?string $status = null,
    ) {
    }

    /** The time to go by. */
    public function time(): ?int
    {
        return $this->expected ?? $this->aimed;
    }
}

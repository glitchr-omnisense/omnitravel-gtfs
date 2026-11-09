<?php

namespace Omnitravel\Gtfs\Estimate;

use Omnitravel\Model\Line;

/**
 * A vehicle of a line where its timetable and real time say it is - not a
 * position it sent: what a map labels "estimated".
 *
 * @see PositionEstimator
 */
final readonly class EstimatedVehicle
{
    /**
     * @param bool  $realtime  real-time predictions moved its times (otherwise the timetable alone)
     * @param ?int  $delay     seconds late (early: negative) at its next stop, when real time says
     * @param ?int  $updatedAt when real time last spoke of the trip (a timestamp)
     */
    public function __construct(
        public string $tripId,
        public ?Line $line,
        public string $headsign,
        public ?int $direction,
        public Estimate $estimate,
        public bool $realtime = false,
        public ?int $delay = null,
        public ?int $updatedAt = null,
    ) {
    }
}

<?php

namespace Omnitravel\Gtfs\Estimate;

use Omnitravel\Model\Coordinates;

/**
 * Where a vehicle is estimated to be: at a stop (between its arrival and its
 * departure), or a fraction of the way from the stop it left to the next
 * one, placed along the trip's shape (or on the straight line between the
 * stops when there is none).
 */
final readonly class Estimate
{
    /**
     * @param string  $from     the stop it is at, or the one it left
     * @param ?string $to       the next stop (null at the last one)
     * @param float   $fraction of the way from $from to $to, 0 to 1 (0 at a stop)
     * @param ?float  $bearing  degrees from north it is heading, when it is moving
     * @param bool    $onShape  placed along the trip's shape (otherwise on the straight line)
     */
    public function __construct(
        public Coordinates $coordinates,
        public string $from,
        public ?string $to,
        public float $fraction,
        public bool $atStop,
        public ?float $bearing = null,
        public bool $onShape = false,
    ) {
    }
}

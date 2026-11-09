<?php

namespace Omnitravel\Gtfs\Estimate;

use Omnitravel\Model\Coordinates;

/**
 * A trip's call as the estimator reads it: where the stop is, and when the
 * vehicle arrives and leaves - real time applied where it is known - as
 * timestamps.
 */
final readonly class Call
{
    public function __construct(
        public string $stop,
        public Coordinates $coordinates,
        public int $arrival,
        public int $departure,
    ) {
    }
}

<?php

namespace Omnitravel\Gtfs\Estimate;

use Omnitravel\Model\Coordinates;

/**
 * Where a vehicle is when its network gives no position: from its trip's
 * calls - the times real time predicts where it is known, the timetable's
 * plus the last known delay otherwise - a pure function of the calls, the
 * moment and the trip's shape.
 *
 * Between its arrival and its departure at a stop, the vehicle is at the
 * stop (its dwell). Between two stops it is
 *
 *     fraction = (now - departure from the previous stop) / (arrival at the next stop - departure from the previous stop)
 *
 * of the way, clamped to 0..1, the way measured along the shape: each stop
 * is projected onto the shape (in order, never backwards), the distance
 * between the two projections cut at the fraction. Without a shape, or when
 * the projections run backwards, on the straight line between the stops.
 * Before its first departure and after its last arrival there is no
 * vehicle (null).
 */
final class PositionEstimator
{
    /**
     * @param list<Call>                     $calls in the trip's order
     * @param list<array{0: float, 1: float}> $shape the trip's shape as [latitude, longitude] points in order
     */
    public static function estimate(array $calls, int $now, array $shape = []): ?Estimate
    {
        $count = \count($calls);
        if (0 === $count || $now < $calls[0]->arrival || $now > $calls[$count - 1]->arrival) {
            return null;
        }
        if ($now < $calls[0]->departure) {
            return new Estimate($calls[0]->coordinates, $calls[0]->stop, $calls[1]->stop ?? null, 0.0, true);
        }
        for ($i = 0; $i < $count - 1; ++$i) {
            [$a, $b] = [$calls[$i], $calls[$i + 1]];
            if ($now >= $a->arrival && $now <= $a->departure) {
                return new Estimate($a->coordinates, $a->stop, $b->stop, 0.0, true);
            }
            if ($now > $a->departure && $now < $b->arrival) {
                $fraction = max(0.0, min(1.0, ($now - $a->departure) / max(1, $b->arrival - $a->departure)));

                return self::between($calls, $i, $fraction, $shape);
            }
        }
        $last = $calls[$count - 1];

        return new Estimate($last->coordinates, $last->stop, null, 0.0, true);
    }

    /**
     * The point a fraction of the way from call $i to the next.
     *
     * @param list<Call>                     $calls
     * @param list<array{0: float, 1: float}> $shape
     */
    private static function between(array $calls, int $i, float $fraction, array $shape): Estimate
    {
        [$a, $b] = [$calls[$i], $calls[$i + 1]];
        if (\count($shape) >= 2) {
            $along = self::along($shape);
            $positions = [];
            $from = 0;
            foreach ($calls as $j => $call) {
                [$positions[$j], $from] = self::project($shape, $along, $call->coordinates, $from);
                if ($j > $i) {
                    break;
                }
            }
            [$start, $end] = [$positions[$i], $positions[$i + 1]];
            if ($end > $start) {
                $target = $start + $fraction * ($end - $start);
                [$point, $bearing] = self::at($shape, $along, $target);

                return new Estimate($point, $a->stop, $b->stop, $fraction, false, $bearing, true);
            }
        }
        $point = new Coordinates(
            $a->coordinates->latitude + $fraction * ($b->coordinates->latitude - $a->coordinates->latitude),
            $a->coordinates->longitude + $fraction * ($b->coordinates->longitude - $a->coordinates->longitude),
        );

        return new Estimate($point, $a->stop, $b->stop, $fraction, false, $a->coordinates->distanceTo($b->coordinates) > 0 ? $a->coordinates->bearingTo($b->coordinates) : null);
    }

    /**
     * The distance travelled at each point of the shape, in metres.
     *
     * @param list<array{0: float, 1: float}> $shape
     *
     * @return list<float>
     */
    private static function along(array $shape): array
    {
        $along = [0.0];
        for ($k = 1, $n = \count($shape); $k < $n; ++$k) {
            $along[] = $along[$k - 1] + self::metres($shape[$k - 1], $shape[$k]);
        }

        return $along;
    }

    /**
     * A point projected onto the shape, from a segment on: the distance
     * travelled at its foot, and the segment it fell on.
     *
     * @param list<array{0: float, 1: float}> $shape
     * @param list<float>                     $along
     *
     * @return array{0: float, 1: int}
     */
    private static function project(array $shape, array $along, Coordinates $point, int $from): array
    {
        $best = [\INF, $along[$from] ?? 0.0, $from];
        $cos = cos(deg2rad($point->latitude));
        for ($k = $from, $n = \count($shape) - 1; $k < $n; ++$k) {
            // A local flat projection is enough over a segment of a few hundred metres.
            [$ax, $ay] = [($shape[$k][1] - $point->longitude) * $cos, $shape[$k][0] - $point->latitude];
            [$bx, $by] = [($shape[$k + 1][1] - $point->longitude) * $cos, $shape[$k + 1][0] - $point->latitude];
            [$dx, $dy] = [$bx - $ax, $by - $ay];
            $length = $dx * $dx + $dy * $dy;
            $t = $length > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $length)) : 0.0;
            $distance = ($ax + $t * $dx) ** 2 + ($ay + $t * $dy) ** 2;
            if ($distance < $best[0]) {
                $best = [$distance, $along[$k] + $t * ($along[$k + 1] - $along[$k]), $k];
            }
        }

        return [$best[1], $best[2]];
    }

    /**
     * The point at a distance travelled along the shape, and its heading.
     *
     * @param list<array{0: float, 1: float}> $shape
     * @param list<float>                     $along
     *
     * @return array{0: Coordinates, 1: ?float}
     */
    private static function at(array $shape, array $along, float $distance): array
    {
        for ($k = 0, $n = \count($shape) - 1; $k < $n; ++$k) {
            if ($distance <= $along[$k + 1] || $k === $n - 1) {
                $span = $along[$k + 1] - $along[$k];
                $t = $span > 0 ? max(0.0, min(1.0, ($distance - $along[$k]) / $span)) : 0.0;
                $a = new Coordinates($shape[$k][0], $shape[$k][1]);
                $b = new Coordinates($shape[$k + 1][0], $shape[$k + 1][1]);

                return [new Coordinates($a->latitude + $t * ($b->latitude - $a->latitude), $a->longitude + $t * ($b->longitude - $a->longitude)), $span > 0 ? $a->bearingTo($b) : null];
            }
        }

        return [new Coordinates($shape[0][0], $shape[0][1]), null];
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function metres(array $a, array $b): float
    {
        return (new Coordinates($a[0], $a[1]))->distanceTo(new Coordinates($b[0], $b[1]));
    }
}

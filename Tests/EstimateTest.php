<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Estimate\Call;
use Omnitravel\Gtfs\Estimate\EstimatedVehicle;
use Omnitravel\Gtfs\Estimate\PositionEstimator;
use Omnitravel\Gtfs\Realtime\WireWriter as W;
use Omnitravel\Model\Coordinates;
use Omnitravel\Testing\RecordedHttpClient;

/**
 * Where a vehicle is when its network sends no position (PositionEstimator,
 * a pure function), and a line's vehicles estimated from the fixture feed
 * and a trip update.
 */
final class EstimateTest extends GtfsTestCase
{
    public function testBetweenTwoStopsTheVehicleIsTheFractionOfTheWayItsTimesSay(): void
    {
        $calls = [self::call('A', 0.0, 0, 0), self::call('B', 0.01, 100, 120), self::call('C', 0.02, 200, 200)];

        $half = PositionEstimator::estimate($calls, 50, []);
        self::assertNotNull($half);
        self::assertSame(['A', 'B', false, false], [$half->from, $half->to, $half->atStop, $half->onShape]);
        self::assertEqualsWithDelta(0.5, $half->fraction, 1e-9);
        self::assertEqualsWithDelta(0.005, $half->coordinates->longitude, 1e-9);
        self::assertEqualsWithDelta(90.0, (float) $half->bearing, 0.1, 'heading east');

        $dwell = PositionEstimator::estimate($calls, 110, []);
        self::assertSame(['B', 'C', true, 0.0], [$dwell?->from, $dwell?->to, $dwell?->atStop, $dwell?->fraction], 'between its arrival and its departure: at the stop');
        self::assertEqualsWithDelta(0.25, PositionEstimator::estimate($calls, 140, [])->fraction ?? -1, 1e-9, 'from its departure, not its arrival');
        self::assertSame(['C', null, true], [PositionEstimator::estimate($calls, 200, [])?->from, PositionEstimator::estimate($calls, 200, [])?->to, PositionEstimator::estimate($calls, 200, [])?->atStop]);
        self::assertNull(PositionEstimator::estimate($calls, -1, []), 'not running yet');
        self::assertNull(PositionEstimator::estimate($calls, 201, []), 'arrived');
        self::assertNull(PositionEstimator::estimate([], 0, []));
    }

    public function testAlongItsShapeTheVehicleFollowsTheTrackNotTheStraightLine(): void
    {
        // North, then east: the stops at both ends of an L.
        $calls = [new Call('A', new Coordinates(0.0, 0.0), 0, 0), new Call('B', new Coordinates(0.01, 0.01), 100, 100)];
        $shape = [[0.0, 0.0], [0.005, 0.0], [0.01, 0.0], [0.01, 0.005], [0.01, 0.01]];

        $half = PositionEstimator::estimate($calls, 50, $shape);
        self::assertNotNull($half);
        self::assertTrue($half->onShape);
        self::assertEqualsWithDelta(0.01, $half->coordinates->latitude, 1e-4, 'at the corner, half the track');
        self::assertEqualsWithDelta(0.0, $half->coordinates->longitude, 1e-4);
        $quarter = PositionEstimator::estimate($calls, 25, $shape);
        self::assertNotNull($quarter);
        self::assertEqualsWithDelta(0.005, $quarter->coordinates->latitude, 1e-4);
        self::assertEqualsWithDelta(0.0, (float) $quarter->bearing, 0.1, 'heading north on the first leg');

        $straight = PositionEstimator::estimate($calls, 50, []);
        self::assertNotNull($straight);
        self::assertEqualsWithDelta(0.005, $straight->coordinates->latitude, 1e-9);
        self::assertEqualsWithDelta(0.005, $straight->coordinates->longitude, 1e-9);
    }

    public function testALinesVehiclesAreEstimatedFromItsTimetableAndItsTripUpdates(): void
    {
        $at = self::at('2026-11-09 08:05');
        $plain = self::provider()->estimatedVehicles('A', $at);
        self::assertSame(['A1' => ['RALL', 'CENTRAL_1', false], 'A3' => ['ROSE', 'CENTRAL_2', false]], self::byTrip($plain));
        self::assertEqualsWithDelta(2 / 7, self::trip($plain, 'A1')->estimate->fraction, 1e-9, 'left Ralliement at 08:03, at the station at 08:10');
        self::assertSame('Roseraie', self::trip($plain, 'A1')->headsign);
        self::assertSame(0, self::trip($plain, 'A1')->direction);

        // A1 three minutes late from Ralliement on; A3 cancelled.
        $late = W::bytes(2, W::bytes(1, 'a1').W::bytes(3, W::bytes(1, W::bytes(1, 'A1')).W::bytes(2, W::varint(1, 2).W::bytes(2, W::int32(1, 180)).W::bytes(3, W::int32(1, 180)).W::bytes(4, 'RALL')).W::varint(4, 1794200000)));
        $gone = W::bytes(2, W::bytes(1, 'a3').W::bytes(3, W::bytes(1, W::bytes(1, 'A3').W::varint(4, 3))));
        $provider = self::provider(['trip_updates_url' => 'https://rt.example.org/trip-updates'], RecordedHttpClient::routes(['~trip-updates~' => [200, W::bytes(1, W::bytes(1, '2.0')).$late.$gone]]));
        $vehicles = $provider->estimatedVehicles('A', $at);

        self::assertSame(['A1' => ['MOLI', 'RALL', false]], self::byTrip($vehicles), 'A1 not at Ralliement before 08:06; A3 cancelled');
        $a1 = self::trip($vehicles, 'A1');
        self::assertEqualsWithDelta(5 / 6, $a1->estimate->fraction, 1e-9);
        self::assertTrue($a1->realtime);
        self::assertSame(180, $a1->delay);
        self::assertSame(1794200000, $a1->updatedAt);
        self::assertSame([], self::provider()->estimatedVehicles('A', self::at('2026-11-09 12:00')), 'nothing runs at noon');
    }

    private static function call(string $stop, float $longitude, int $arrival, int $departure): Call
    {
        return new Call($stop, new Coordinates(0.0, $longitude), $arrival, $departure);
    }

    /**
     * @param list<EstimatedVehicle> $vehicles
     *
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    private static function byTrip(array $vehicles): array
    {
        $by = [];
        foreach ($vehicles as $vehicle) {
            $by[$vehicle->tripId] = [$vehicle->estimate->from, $vehicle->estimate->to, $vehicle->estimate->atStop];
        }
        ksort($by);

        return $by;
    }

    /** @param list<EstimatedVehicle> $vehicles */
    private static function trip(array $vehicles, string $trip): EstimatedVehicle
    {
        foreach ($vehicles as $vehicle) {
            if ($trip === $vehicle->tripId) {
                return $vehicle;
            }
        }
        self::fail("No vehicle of trip $trip.");
    }
}

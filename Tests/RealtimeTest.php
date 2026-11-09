<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Realtime\FeedMessage;
use Omnitravel\Gtfs\Realtime\WireReader;
use Omnitravel\Gtfs\Realtime\WireWriter as W;
use Omnitravel\Model\BoundingBox;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\VehicleQuery;
use Omnitravel\Model\VehicleStatus;
use Omnitravel\Testing\RecordedHttpClient;

/**
 * GTFS-Realtime laid over the fixture feed: feeds written with WireWriter as
 * a network would publish them, and two feeds of Angers' network (Irigo,
 * ODbL) recorded on 2026-11-09 at 12:54 UTC.
 */
final class RealtimeTest extends GtfsTestCase
{
    private const TRIP_UPDATES = 'https://rt.example.org/trip-updates';
    private const VEHICLES = 'https://rt.example.org/vehicle-positions';

    public function testDelaysPropagateDownTheTripCancellationsAndSkippedStopsAreSaid(): void
    {
        $http = self::http();
        $provider = self::provider(['trip_updates_url' => self::TRIP_UPDATES, 'realtime_headers' => ['apikey' => 'not-a-real-key'], 'user_agent' => 'tests/1.0'], $http);
        $departures = $provider->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 5));

        self::assertSame([
            ['08:05', '3', true, true, null],      // B1 skips the station
            ['08:12', 'A', false, false, null],    // A3: nothing said
            ['08:13', 'A', true, false, 180],      // A1: 3 minutes late at Ralliement, so at the station
            ['08:30', 'A', true, true, null],      // A2 cancelled
            ['09:04', '3', true, false, -60],      // B2: a minute early, by its time
        ], array_map(static fn (Departure $d) => [$d->time()->format('H:i'), $d->line?->name, $d->realtime, $d->cancelled, $d->delay()], $departures));
        self::assertNull($provider->realtimeError());
        self::assertCount(1, $http->requests, 'read once for the whole timetable');
        self::assertSame(self::TRIP_UPDATES, $http->requests[0]['url']);
        self::assertSame('not-a-real-key', $http->header('apikey'));
        self::assertSame('tests/1.0', $http->header('User-Agent'));
        self::assertSame(['timetables', 'network'], $provider->capabilities()->contracts());
        self::assertTrue($provider->capabilities()->realtime);
    }

    public function testADelayedCallScheduledBeforeTheMomentAskedIsStillListed(): void
    {
        $departures = self::provider(['trip_updates_url' => self::TRIP_UPDATES], self::http())->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 08:11'), 2));

        self::assertSame(['08:12', '08:13'], array_map(static fn (Departure $d) => $d->time()->format('H:i'), $departures), 'A1 left 08:10 on paper, 08:13 indeed');
    }

    public function testAFeedThatCannotBeReadLeavesTheTimetable(): void
    {
        $provider = self::provider(['trip_updates_url' => self::TRIP_UPDATES], RecordedHttpClient::routes(['~trip-updates~' => [503, 'Down for maintenance']]));
        $departures = $provider->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 2));

        self::assertSame(['08:05', '08:10'], array_map(static fn (Departure $d) => $d->time()->format('H:i'), $departures));
        self::assertFalse($departures[0]->realtime);
        self::assertSame('GTFS-Realtime (test) answered 503 for GET /trip-updates. Down for maintenance', $provider->realtimeError());
    }

    public function testAReadIsKeptInTheCacheFolderForItsTime(): void
    {
        $dir = sys_get_temp_dir().'/omnitravel-rt-'.bin2hex(random_bytes(4));
        $store = self::store();
        try {
            $first = self::http();
            self::provider(['trip_updates_url' => self::TRIP_UPDATES, 'realtime_cache' => $dir, 'realtime_ttl' => 60], $first, $store)->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 1));
            $second = self::http();
            $departures = self::provider(['trip_updates_url' => self::TRIP_UPDATES, 'realtime_cache' => $dir, 'realtime_ttl' => 60], $second, $store)->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 1));

            self::assertCount(1, $first->requests);
            self::assertCount(0, $second->requests, 'read from the folder');
            self::assertTrue($departures[0]->cancelled);
            self::assertCount(1, glob($dir.'/test-*.pb') ?: []);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testVehiclesWithTheirLineFromTheirTrip(): void
    {
        $provider = self::provider(['vehicle_positions_url' => self::VEHICLES], self::http());
        $vehicles = $provider->vehicles(new VehicleQuery());

        self::assertCount(2, $vehicles);
        [$tram, $bus] = $vehicles;
        self::assertSame(['tram-07', 'A', 'A1', 90.0, VehicleStatus::InTransit, 'RALL', '2026-11-09T07:05:00+00:00'], [$tram->vehicle, $tram->line?->name, $tram->tripId, $tram->bearing, $tram->status, $tram->stopId, $tram->time->format(\DATE_ATOM)]);
        self::assertEqualsWithDelta(47.4712, $tram->coordinates->latitude, 1e-5);
        self::assertEqualsWithDelta(8.5, $tram->speed, 1e-5);
        self::assertSame('3', $bus->line?->name, 'its route given in the trip descriptor');
        self::assertSame(['tram-07'], array_map(static fn ($v) => $v->vehicle, $provider->vehicles(new VehicleQuery(BoundingBox::around(new Coordinates(47.4712, -0.5518), 100)))));
        self::assertSame(['bus-31'], array_map(static fn ($v) => $v->vehicle, $provider->vehicles(new VehicleQuery(line: '3'))));
        self::assertSame(['timetables', 'tracking', 'network'], $provider->capabilities()->contracts());
    }

    public function testARealNetworksFeedsAreRead(): void
    {
        $trips = FeedMessage::parse((string) file_get_contents(__DIR__.'/Fixtures/angers-trip-updates.pb'));
        $vehicles = FeedMessage::parse((string) file_get_contents(__DIR__.'/Fixtures/angers-vehicle-positions.pb'));

        self::assertSame('2.0', $trips->version);
        self::assertSame('2026-11-09', date('Y-m-d', (int) $trips->timestamp));
        self::assertGreaterThan(10, \count($trips->tripUpdates));
        $withTimes = array_filter($trips->tripUpdates, static fn ($u) => [] !== array_filter($u->stops, static fn ($s) => $s->hasTime()));
        self::assertNotEmpty($withTimes);
        foreach ($trips->tripUpdates as $update) {
            self::assertNotNull($update->tripId);
            self::assertMatchesRegularExpression('~^\d{8}$~', (string) ($update->startDate ?? '20261109'));
        }
        self::assertGreaterThan(5, \count($vehicles->vehicles));
        foreach ($vehicles->vehicles as $vehicle) {
            // Angers and its surroundings.
            self::assertTrue((new BoundingBox(47.2, -0.9, 47.7, -0.2))->contains(new Coordinates((float) $vehicle->latitude, (float) $vehicle->longitude)), (string) $vehicle->vehicle);
        }
    }

    public function testTheWireFormatsNumbers(): void
    {
        $message = W::int32(1, -60).W::varint(2, 1_762_675_200).W::float32(3, 47.5).W::bytes(4, 'Ralliement');
        $fields = iterator_to_array(WireReader::fields($message), false);

        self::assertSame([1, 2, 3, 4], array_column($fields, 0));
        self::assertSame(11, \strlen(W::int32(1, -60)), 'a negative int32 takes ten bytes, after its key');
        self::assertSame(-60, WireReader::int32((int) $fields[0][2]));
        self::assertSame(1_762_675_200, $fields[1][2]);
        self::assertSame(47.5, WireReader::float32((string) $fields[2][2]));
        self::assertSame('Ralliement', $fields[3][2]);

        $this->expectExceptionMessage('Not a GTFS-Realtime message');
        iterator_to_array(WireReader::fields("\x0a\x05abc"));
    }

    /** The fixture network's real-time feeds, as WireWriter writes them. */
    private static function http(): RecordedHttpClient
    {
        $trip = static fn (string $id, int $relationship = 0, ?string $route = null): string => W::bytes(1, $id).W::bytes(3, '20261109').W::varint(4, $relationship).(null === $route ? '' : W::bytes(5, $route));
        $call = static fn (int $seq, string $stop, ?int $delay = null, ?int $time = null, int $relationship = 0): string => W::varint(1, $seq)
            .(null === $delay && null === $time ? '' : W::bytes(3, (null === $delay ? '' : W::int32(1, $delay)).(null === $time ? '' : W::varint(2, $time))))
            .W::bytes(4, $stop).W::varint(5, $relationship);
        $entity = static fn (string $id, int $field, string $body): string => W::bytes(2, W::bytes(1, $id).W::bytes($field, $body));
        $paris = static fn (string $time) => (new \DateTimeImmutable('2026-11-09 '.$time, new \DateTimeZone('Europe/Paris')))->getTimestamp();
        $header = W::bytes(1, W::bytes(1, '2.0').W::varint(3, $paris('08:05')));

        $updates = $header
            .$entity('a1', 3, W::bytes(1, $trip('A1')).W::bytes(2, $call(2, 'RALL', 180)))
            .$entity('a2', 3, W::bytes(1, $trip('A2', 3)))
            .$entity('b1', 3, W::bytes(1, $trip('B1')).W::bytes(2, $call(1, 'CENTRAL_2', null, null, 1)).W::bytes(2, $call(2, 'RALL', 0)))
            .$entity('b2', 3, W::bytes(1, $trip('B2')).W::bytes(2, $call(1, 'CENTRAL_2', null, $paris('09:04'))))
            .W::bytes(2, W::bytes(1, 'gone').W::varint(2, 1).W::bytes(3, W::bytes(1, $trip('A3', 3))));
        $position = static fn (float $lat, float $lon, ?float $bearing = null, ?float $speed = null): string => W::float32(1, $lat).W::float32(2, $lon).(null === $bearing ? '' : W::float32(3, $bearing)).(null === $speed ? '' : W::float32(5, $speed));
        $vehicles = $header
            .$entity('v1', 4, W::bytes(1, $trip('A1')).W::bytes(2, $position(47.4712, -0.5518, 90.0, 8.5)).W::varint(4, 2).W::varint(5, $paris('08:05')).W::bytes(7, 'RALL').W::bytes(8, W::bytes(1, 'tram-07').W::bytes(2, '07')))
            .$entity('v2', 4, W::bytes(1, $trip('unknown-trip', 0, '3')).W::bytes(2, $position(47.4650, -0.5560)).W::bytes(8, W::bytes(1, 'bus-31')))
            .$entity('v3', 4, W::bytes(1, $trip('A2')).W::bytes(2, $position(0.0, 0.0)).W::bytes(8, W::bytes(1, 'no-fix')));

        return RecordedHttpClient::routes(['~trip-updates~' => [200, $updates], '~vehicle-positions~' => [200, $vehicles]]);
    }
}

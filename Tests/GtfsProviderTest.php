<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Exception\InvalidConfigException;
use Omnitravel\Exception\NotSupportedException;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Stop;
use Omnitravel\Model\StopKind;
use Omnitravel\Model\VehicleQuery;
use Omnitravel\Registry;
use Omnitravel\Gtfs\GtfsFactory;

final class GtfsProviderTest extends GtfsTestCase
{
    public function testTheStopsNearAPointComeNearestFirstWithTheirLines(): void
    {
        $provider = self::provider();
        $near = $provider->nearby(new Coordinates(47.4710, -0.5520), 500);

        self::assertSame(['RALL', 'MOLI'], array_map(static fn (Stop $s) => $s->id, $near));
        self::assertEqualsWithDelta(27, $near[0]->distance, 2);
        self::assertSame(['A', '3'], array_map(static fn (Line $l) => $l->name, $near[0]->lines), 'the tram first: its sort order');

        $wider = $provider->nearby(new Coordinates(47.4710, -0.5520), 1000);
        self::assertSame(['RALL', 'MOLI', 'CENTRAL'], array_map(static fn (Stop $s) => $s->id, $wider), 'a station, never its platforms');
        self::assertSame(StopKind::Station, $wider[2]->kind);
        self::assertSame(['A', '3'], array_map(static fn (Line $l) => $l->name, $wider[2]->lines), 'a station\'s lines: its platforms\'');
        self::assertSame([], $provider->nearby(new Coordinates(48.85, 2.35)));
    }

    public function testStopsAreFoundByTheirNameWithoutAccents(): void
    {
        $provider = self::provider();

        self::assertSame(['FAR'], array_map(static fn (Stop $s) => $s->id, $provider->searchStops('venaisiere')));
        self::assertSame(['FAR'], array_map(static fn (Stop $s) => $s->id, $provider->searchStops('Saint-Barth')));
        self::assertSame(['CENTRAL'], array_map(static fn (Stop $s) => $s->id, $provider->searchStops('gare')));
        self::assertSame(['MOLI'], array_map(static fn (Stop $s) => $s->id, $provider->searchStops('MOLIÈRE')));
        self::assertSame([], $provider->searchStops(' - '));
        self::assertSame([], $provider->searchStops('100%'));

        $stop = $provider->stop('CENTRAL_2');
        self::assertNotNull($stop);
        self::assertSame(['Gare Centrale', '2', 'CENTRAL', '102', true], [$stop->name, $stop->platform, $stop->parentId, $stop->code, $stop->wheelchair]);
        self::assertSame(['A', '3'], array_map(static fn (Line $l) => $l->name, $stop->lines));
        self::assertNull($provider->stop('NOWHERE'));
        self::assertFalse($provider->stop('MOLI')?->wheelchair);
    }

    public function testLinesComeInTheFeedsOrderWithTheirStops(): void
    {
        $provider = self::provider();
        [$a, $bus] = $provider->lines();

        self::assertSame(['A', 'Molière - Roseraie', Mode::Tram, '#E2001A', '#FFFFFF', 'Example Transit'], [$a->name, $a->longName, $a->mode, $a->color, $a->textColor, $a->operator?->name]);
        self::assertSame(['3', Mode::Bus, '#F18700', '#000000'], [$bus->name, $bus->mode, $bus->color, $bus->textColor], 'no text colour given: the readable one');
        self::assertSame(['MOLI', 'RALL', 'CENTRAL_1', 'ROSE'], array_map(static fn (Stop $s) => $s->id, $provider->lineStops('A')));
        self::assertSame(['ROSE', 'CENTRAL_2', 'RALL', 'MOLI'], array_map(static fn (Stop $s) => $s->id, $provider->lineStops('A', '1')));
        self::assertSame(['0', '1'], $provider->lineDirections('A'));
        self::assertSame([], $provider->lineStops('Z'));
        self::assertSame($a, $provider->line('A'));
        self::assertSame(['Example Transit'], array_map(static fn ($o) => $o->name, $provider->operators()));
    }

    public function testAStationsDeparturesFromItsPlatformsInTime(): void
    {
        $departures = self::provider()->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 7));

        self::assertSame([
            ['2026-11-09 08:05', '3', 'Saint-Barthélemy', '2'],
            ['2026-11-09 08:10', 'A', 'Roseraie', '1'],
            ['2026-11-09 08:12', 'A', 'Molière', '2'],
            ['2026-11-09 08:30', 'A', 'Roseraie', '1'],
            ['2026-11-09 09:05', '3', 'Saint-Barthélemy', '2'],
            ['2026-11-10 01:00', 'A', 'Roseraie', '1'],
            ['2026-11-10 08:05', '3', 'Saint-Barthélemy', '2'],
        ], self::rows($departures));
        self::assertSame('Europe/Paris', $departures[0]->scheduled->getTimezone()->getName());
        self::assertFalse($departures[0]->realtime);
        self::assertSame('CENTRAL_2', $departures[0]->stop->id);
    }

    public function testATripOfYesterdaysServiceLeavesAfterMidnight(): void
    {
        $departures = self::provider()->departures(new DepartureQuery('RALL', self::at('2026-11-10 00:30'), 2));

        self::assertSame([['2026-11-10 00:53', 'A', 'Roseraie', null], ['2026-11-10 08:03', 'A', 'Roseraie', null]], self::rows($departures));
    }

    public function testAHolidayRemovesItsServiceAndTheNextDaysComeInstead(): void
    {
        $departures = self::provider()->departures(new DepartureQuery('CENTRAL', self::at('2026-11-11 07:00'), 1));

        self::assertSame([['2026-11-12 08:05', '3', 'Saint-Barthélemy', '2']], self::rows($departures));
        self::assertSame([['2026-11-15 10:10', 'A', 'Roseraie', '1']], self::rows(self::provider()->departures(new DepartureQuery('CENTRAL', self::at('2026-11-15 07:00'), 1))), 'Sunday\'s service');
    }

    public function testATerminusHasArrivalsNotDepartures(): void
    {
        $provider = self::provider();
        $from = self::at('2026-11-09 07:00');

        self::assertSame(['08:00'], array_map(static fn (Departure $d) => $d->scheduled->format('H:i'), $provider->departures(new DepartureQuery('ROSE', $from, 5, 3600))), 'the trip that starts there only');
        $arrivals = $provider->departures(new DepartureQuery('ROSE', $from, 2, arrivals: true));
        self::assertSame(['08:20', '08:40'], array_map(static fn (Departure $d) => $d->scheduled->format('H:i'), $arrivals));
        self::assertTrue($arrivals[0]->arrival);
    }

    public function testDeparturesNarrowedToALineAModeOrAWindow(): void
    {
        $provider = self::provider();
        $from = self::at('2026-11-09 07:55');

        self::assertSame(['3', '3'], array_map(static fn (Departure $d) => $d->line?->name, $provider->departures(new DepartureQuery('CENTRAL', $from, 2, lines: ['3']))));
        self::assertSame(['A', 'A'], array_map(static fn (Departure $d) => $d->line?->name, $provider->departures(new DepartureQuery('CENTRAL', $from, 2, modes: [Mode::Tram]))));
        self::assertCount(3, $provider->departures(new DepartureQuery('CENTRAL', $from, 10, window: 1200)));
        self::assertSame([], $provider->departures(new DepartureQuery('NOWHERE', $from)));
    }

    public function testCapabilitiesSayTheFeedsAreaAndWhatItLacks(): void
    {
        $provider = self::provider();
        $capabilities = $provider->capabilities();

        self::assertSame(['timetables', 'network'], $capabilities->contracts());
        self::assertFalse($capabilities->realtime);
        self::assertSame([Mode::Tram, Mode::Bus], $capabilities->modes, 'in the lines\' order');
        self::assertSame(['FR'], $capabilities->countries);
        self::assertSame('Example Transit, ODbL', $capabilities->attribution);
        self::assertNotNull($capabilities->area);
        self::assertTrue($capabilities->area->contains(new Coordinates(47.47, -0.55)));
        self::assertSame('Example Transit', $provider->getTitle());

        $this->expectException(NotSupportedException::class);
        $provider->vehicles(new VehicleQuery());
    }

    public function testTheFactoryNeedsAFeedAndAStoreAndTheRegistryFindsItByItsArea(): void
    {
        $provider = self::provider();
        $registry = new Registry([new class($provider) extends \Omnitravel\ProviderFactory {
            public function __construct(private readonly \Omnitravel\ProviderInterface $provider)
            {
            }

            protected function populate(\Omnitravel\Config $c): void
            {
                $c->defaults(['omnitravel.factory_name' => 'imported']);
            }

            protected function build(\Omnitravel\Config $c): \Omnitravel\ProviderInterface
            {
                return $this->provider;
            }
        }], ['angers' => ['factory' => 'imported']]);
        self::assertSame(['angers'], $registry->covering(new Coordinates(47.47, -0.55)));
        self::assertSame([], $registry->covering(new Coordinates(43.6, 1.44)));

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "gtfs" provider needs: feed, dsn.');
        (new GtfsFactory())->create();
    }

    /**
     * @param list<Departure> $departures
     *
     * @return list<array{0: string, 1: ?string, 2: string, 3: ?string}>
     */
    private static function rows(array $departures): array
    {
        return array_map(static fn (Departure $d) => [$d->time()->format('Y-m-d H:i'), $d->line?->name, $d->headsign, $d->platform], $departures);
    }
}

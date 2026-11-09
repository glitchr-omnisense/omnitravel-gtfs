<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Feed;
use Omnitravel\Gtfs\FeedFilter;
use Omnitravel\Gtfs\Importer;
use Omnitravel\Gtfs\Store;

/** A feed imported in part (FeedFilter) with what its routes need, and the trips' shapes. */
final class FilterTest extends GtfsTestCase
{
    public function testAFilteredImportKeepsItsRoutesAndWhatTheyNeedWithTheirShapes(): void
    {
        $store = new Store(new \PDO('sqlite::memory:'));
        $report = (new Importer($store))->import(new Feed(self::feedWithShape()), 'test', filter: new FeedFilter(routeTypes: [0]));

        self::assertSame(['routes.txt' => 1, 'trips.txt' => 5, 'shapes.txt' => 3, 'calendar.txt' => 2, 'stop_times.txt' => 20], array_intersect_key($report->rows, ['routes.txt' => 0, 'trips.txt' => 0, 'shapes.txt' => 0, 'calendar.txt' => 0, 'stop_times.txt' => 0]));
        $stops = array_column($store->rows("SELECT stop_id FROM gtfs_stop WHERE feed = 'test' ORDER BY stop_id"), 'stop_id');
        self::assertSame(['CENTRAL', 'CENTRAL_1', 'CENTRAL_2', 'MOLI', 'RALL', 'ROSE'], $stops, 'FAR is line 3\'s alone; the station of the platforms called at stays');
        self::assertSame([], $store->rows("SELECT service_id FROM gtfs_calendar_date WHERE service_id = 'EXTRA'"), 'line 3\'s service left out');

        $provider = self::provider(store: $store);
        self::assertCount(3, $provider->shape('A1'));
        self::assertSame([], $provider->shape('A2'));
        self::assertSame(['A'], array_map(static fn ($line) => $line->id, $provider->lines()));
        self::assertSame(['Tram A'], array_map(static fn ($line) => 'Tram '.$line->name, $provider->lines()));

        $excluded = new Store(new \PDO('sqlite::memory:'));
        (new Importer($excluded))->import(new Feed(self::FEED), 'test', filter: new FeedFilter(agencies: ['TEST'], excludeRoutes: ['3']));
        self::assertSame(['A'], array_column($excluded->rows("SELECT route_id FROM gtfs_route WHERE feed = 'test'"), 'route_id'));
        self::assertTrue(FeedFilter::fromArray(['agencies' => 'X, Y', 'route_types' => [0, '1']])->accepts(['route_id' => 'r', 'agency_id' => 'Y', 'route_type' => '1']));
    }

    /** The fixture feed with a shape for trip A1, in a folder of its own. */
    private static function feedWithShape(): string
    {
        $dir = sys_get_temp_dir().'/omnitravel-shape-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (glob(self::FEED.'/*.txt') ?: [] as $file) {
            copy($file, $dir.'/'.basename($file));
        }
        $trips = (string) file_get_contents($dir.'/trips.txt');
        $lines = explode("\n", trim($trips));
        $lines[0] .= ',shape_id';
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $lines[$i] .= str_starts_with($line, 'A,WEEK,A1,') ? ',SH1' : ',';
            }
        }
        file_put_contents($dir.'/trips.txt', implode("\n", $lines)."\n");
        file_put_contents($dir.'/shapes.txt', "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,47.4729,-0.5540,1\nSH1,47.4712,-0.5518,2\nSH1,47.4650,-0.5560,3\nOTHER,1,1,1\n");

        return $dir;
    }
}

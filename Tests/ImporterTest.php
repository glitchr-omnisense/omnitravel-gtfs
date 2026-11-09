<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Exception\InvalidArgumentException;
use Omnitravel\Gtfs\Cast;
use Omnitravel\Gtfs\Feed;
use Omnitravel\Gtfs\Importer;
use Omnitravel\Gtfs\Store;
use Omnitravel\Gtfs\Zip;

final class ImporterTest extends GtfsTestCase
{
    public function testAFeedIsImportedWithWhatTheProviderReadsFast(): void
    {
        $store = new Store(new \PDO('sqlite::memory:'));
        $told = [];
        $report = (new Importer($store))->import(new Feed(self::FEED), 'test', ['title' => 'Example'], static function (string $file, int $rows) use (&$told): void {
            $told[] = $file;
        });

        self::assertSame(['agency.txt' => 1, 'stops.txt' => 7, 'routes.txt' => 2, 'trips.txt' => 7, 'calendar.txt' => 2, 'calendar_dates.txt' => 2, 'stop_times.txt' => 26, 'route stops' => 11, 'stop routes' => 8], $report->rows);
        self::assertSame(array_keys($report->rows), $told);
        self::assertSame([1, 0], array_map(Cast::int(...), array_values($store->row("SELECT is_first, is_last FROM gtfs_stop_time WHERE feed = 'test' AND trip_id = 'A1' AND seq = 1") ?? [])));
        self::assertSame(90000, Cast::int($store->row("SELECT departure FROM gtfs_stop_time WHERE feed = 'test' AND trip_id = 'A9' AND seq = 3")['departure'] ?? 0), '25:00:00');
        self::assertSame('saint barthelemy venaisiere', $store->row("SELECT search FROM gtfs_stop WHERE stop_id = 'FAR'")['search'] ?? null);
        self::assertSame(0b11111, Cast::int($store->row("SELECT days FROM gtfs_calendar WHERE service_id = 'WEEK'")['days'] ?? 0));

        $info = self::provider(store: $store)->info();
        self::assertNotNull($info);
        self::assertSame(['Example', 'Europe/Paris', 'Example Transit', '2026-11', '2026-12-31', 7, 7, 26], [$info->title, $info->timezone, $info->publisher, $info->version, $info->end?->format('Y-m-d'), $info->stops, $info->trips, $info->stopTimes]);
        self::assertTrue($info->covers(new \DateTimeImmutable('2026-11-09')));
        self::assertFalse($info->covers(new \DateTimeImmutable('2027-01-01')));
    }

    public function testAnImportReplacesTheFeedAndLeavesTheOthers(): void
    {
        $store = self::store();
        (new Importer($store))->import(new Feed(self::FEED), 'other');
        (new Importer($store))->import(new Feed(self::FEED), 'test');

        self::assertSame(14, Cast::int($store->row('SELECT COUNT(*) AS n FROM gtfs_stop')['n'] ?? 0));
        self::assertSame(7, Cast::int($store->row("SELECT COUNT(*) AS n FROM gtfs_stop WHERE feed = 'test'")['n'] ?? 0));
    }

    /** A zip archive, deflated, reads as its folder: through ZipArchive, or Zip without PHP's zip extension. */
    public function testAZipArchiveReadsAsItsFolder(): void
    {
        $zip = sys_get_temp_dir().'/omnitravel-gtfs-'.bin2hex(random_bytes(4)).'.zip';
        $archive = new \PharData($zip, 0, null, \Phar::ZIP);
        foreach (glob(self::FEED.'/*.txt') ?: [] as $file) {
            $archive->addFile($file, 'gtfs/'.basename($file));
        }
        $archive->compressFiles(\Phar::GZ);
        unset($archive);
        try {
            $feed = new Feed($zip);
            self::assertContains('stop_times.txt', $feed->files());
            $first = $feed->rows('stops.txt')->current();
            self::assertSame(['stop_id', 'stop_code', 'stop_name', 'stop_lat', 'stop_lon', 'location_type', 'parent_station', 'platform_code', 'wheelchair_boarding'], array_keys($first), 'the byte order mark dropped');
            $report = (new Importer(new Store(new \PDO('sqlite::memory:'))))->import($feed, 'zipped');
            self::assertSame(26, $report->count('stop_times.txt'));
            $zipped = new Zip($zip);
            self::assertSame((string) file_get_contents(self::FEED.'/stop_times.txt'), stream_get_contents($zipped->open('stop_times.txt')), 'inflated by zlib alone');
        } finally {
            @unlink($zip);
        }
    }

    public function testWhatIsNotAFeedIsRefused(): void
    {
        try {
            new Feed(__DIR__.'/Fixtures');
            self::fail('no exception');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Not a GTFS feed: "Fixtures" has no stops.txt.', $e->getMessage());
        }

        $this->expectExceptionMessage('A feed\'s name is made of');
        (new Importer(new Store(new \PDO('sqlite::memory:'))))->import(new Feed(self::FEED), 'Not A Name');
    }

    public function testTimesPastMidnightAreSecondsOfTheServiceDay(): void
    {
        self::assertSame([28800, 90605, 0, null, null], [Importer::seconds('08:00:00'), Importer::seconds('25:10:05'), Importer::seconds('0:00:00'), Importer::seconds(''), Importer::seconds('8h')]);
    }
}

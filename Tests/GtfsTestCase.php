<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Feed;
use Omnitravel\Gtfs\GtfsFactory;
use Omnitravel\Gtfs\GtfsProvider;
use Omnitravel\Gtfs\Importer;
use Omnitravel\Gtfs\Store;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The fixture feed (Tests/Fixtures/feed: a station of two platforms, a tram and a bus line, a night trip, a holiday) imported into an SQLite store in memory. */
abstract class GtfsTestCase extends TestCase
{
    protected const FEED = __DIR__.'/Fixtures/feed';

    protected static function store(): Store
    {
        $store = new Store(new \PDO('sqlite::memory:'));
        (new Importer($store))->import(new Feed(self::FEED), 'test');

        return $store;
    }

    /** @param array<string, mixed> $options */
    protected static function provider(array $options = [], ?HttpClientInterface $http = null, ?Store $store = null): GtfsProvider
    {
        $factory = new GtfsFactory($http);
        $store ??= self::store();
        // The factory opens its stores by DSN; a store in memory is handed over through the same cache.
        (new \ReflectionProperty(GtfsFactory::class, 'stores'))->setValue($factory, ['sqlite::memory:' => $store]);
        $provider = $factory->create($options + ['feed' => 'test', 'dsn' => 'sqlite::memory:', 'countries' => 'fr', 'attribution' => 'Example Transit, ODbL']);
        self::assertInstanceOf(GtfsProvider::class, $provider);

        return $provider;
    }

    protected static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Paris'));
    }
}

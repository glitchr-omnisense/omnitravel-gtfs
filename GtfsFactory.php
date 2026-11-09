<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Config;
use Omnitravel\Gtfs\Siri\SiriLite;
use Omnitravel\Http\JsonClient;
use Omnitravel\ProviderFactory;
use Omnitravel\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GtfsProvider's factory: "gtfs", one feed of a store.
 *
 * Options: feed (required: its name in the store), dsn (required: "sqlite:/path/gtfs.sqlite",
 * "mysql:host=...;dbname=..."), user, password; trip_updates_url, vehicle_positions_url and
 * alerts_url (its GTFS-Realtime feeds), realtime_headers (sent with them: a key, where the network asks one),
 * realtime_ttl (seconds a real-time read is kept, 30), realtime_cache (a folder where it is kept
 * between requests); timezone (the agency's otherwise), title, attribution, licence, countries.
 *
 * SIRI Lite, for networks that publish SIRI rather than GTFS-Realtime (Siri\SiriLite):
 * siri_stop_monitoring_url, siri_general_message_url, siri_headers (a key, by reference), the
 * templates siri_stop_ref, siri_stop_area_ref and siri_line_ref ("STIF:StopPoint:Q:{id}:"), and
 * siri_prefix (the GTFS identifiers' prefix "{id}" leaves out, "IDFM:").
 *
 * Feeds of one DSN share one connection. The HTTP client is used for the real-time feeds only:
 * a feed without them calls nothing.
 */
final class GtfsFactory extends ProviderFactory
{
    /** @var array<string, Store> */
    private array $stores = [];

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    /** The store of a DSN, opened once: what the importer writes into. */
    public function store(string $dsn, ?string $user = null, ?string $password = null): Store
    {
        return $this->stores[$dsn] ??= Store::connect($dsn, $user, $password);
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnitravel.factory_name' => 'gtfs',
            'omnitravel.factory_title' => 'GTFS',
            'omnitravel.required_options' => ['feed', 'dsn'],
            'realtime_ttl' => 30,
            'countries' => [],
            'realtime_headers' => [],
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $realtime = null !== $c->string('trip_updates_url') || null !== $c->string('vehicle_positions_url') || null !== $c->string('alerts_url');
        $siri = null;
        if (null !== $c->string('siri_stop_monitoring_url') || null !== $c->string('siri_general_message_url')) {
            $siriHeaders = $c->get('siri_headers');
            $siriHeaders = \is_array($siriHeaders) ? array_filter(array_map(static fn ($v) => \is_scalar($v) ? (string) $v : null, $siriHeaders), static fn ($v, $k) => null !== $v && '' !== $v && \is_string($k), \ARRAY_FILTER_USE_BOTH) : [];
            $siri = new SiriLite(
                new JsonClient($this->http ?? HttpClient::create(['timeout' => 10]), 'SIRI Lite ('.$c->string('feed').')', ['User-Agent' => $c->string('user_agent') ?? 'omnitravel-gtfs/1.0'] + $siriHeaders),
                $c->string('siri_stop_monitoring_url'), $c->string('siri_general_message_url'),
                $c->string('siri_stop_ref') ?? '{id}', $c->string('siri_stop_area_ref'), $c->string('siri_line_ref') ?? '{id}', $c->string('siri_prefix') ?? '',
                max(0, $c->int('realtime_ttl', 30)), $c->string('realtime_cache'), (string) $c->string('feed'),
            );
        }
        $headers = $c->get('realtime_headers');
        $headers = \is_array($headers) ? array_filter(array_map(static fn ($v) => \is_scalar($v) ? (string) $v : null, $headers), static fn ($v, $k) => null !== $v && \is_string($k), \ARRAY_FILTER_USE_BOTH) : [];
        $countries = $c->get('countries');
        $countries = \is_string($countries) ? explode(',', $countries) : (\is_array($countries) ? $countries : []);

        return new GtfsProvider(
            $this->store((string) $c->string('dsn'), $c->string('user'), $c->string('password')),
            (string) $c->string('feed'),
            $realtime ? new JsonClient($this->http ?? HttpClient::create(['timeout' => 10]), 'GTFS-Realtime ('.$c->string('feed').')', ['User-Agent' => $c->string('user_agent') ?? 'omnitravel-gtfs/1.0'] + $headers) : null,
            $c->string('trip_updates_url'),
            $c->string('vehicle_positions_url'),
            max(0, $c->int('realtime_ttl', 30)),
            $c->string('realtime_cache'),
            $c->string('timezone'),
            $c->string('title'),
            $c->string('attribution'),
            $c->string('licence'),
            array_values(array_filter(array_map(static fn ($country) => \is_string($country) ? strtoupper(trim($country)) : '', $countries))),
            alertsUrl: $c->string('alerts_url'),
            siri: $siri,
        );
    }
}

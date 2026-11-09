<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Exception\InvalidArgumentException;

/**
 * Writes a static GTFS feed into a Store under a name, in one transaction:
 * the feed's earlier rows go, its agencies, stops, routes, trips,
 * calendars and stop times come, then what the provider reads fast - each
 * route's stops in order (per direction, from its trip with the most
 * stops) and the routes calling at each stop. Readers keep seeing the
 * previous import until the new one is committed.
 */
final class Importer
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param string                                           $name     the feed's name in the store ("angers")
     * @param array{title?: string, url?: string}              $meta     what the feed does not say of itself
     * @param ?\Closure(string $file, int $rows): void         $progress told after each file
     * @param ?FeedFilter                                      $filter   the routes to import and what they need; all of it by default
     */
    public function import(Feed $feed, string $name, array $meta = [], ?\Closure $progress = null, ?FeedFilter $filter = null): ImportReport
    {
        if (!preg_match('~^[a-z0-9][a-z0-9_.-]{0,63}$~', $name)) {
            throw new InvalidArgumentException(\sprintf('A feed\'s name is made of a-z, 0-9, ".", "_" and "-": "%s" is not.', $name));
        }
        $started = microtime(true);
        $this->store->createSchema();
        $keep = null === $filter || $filter->isEmpty() ? null : $this->choose($feed, $filter);
        /** @var array<string, int> $counts */
        $counts = $this->store->transaction(function () use ($feed, $name, $meta, $progress, $keep): array {
            $counts = [];
            $tell = static function (string $file, int $rows) use (&$counts, $progress): void {
                $counts[$file] = $rows;
                if (null !== $progress) {
                    $progress($file, $rows);
                }
            };
            $this->store->deleteFeed($name);

            $timezone = null;
            $tell('agency.txt', $this->store->insert('gtfs_agency', ['feed', 'agency_id', 'name', 'url', 'timezone', 'phone'], (function () use ($feed, $name, &$timezone) {
                foreach ($feed->rows('agency.txt') as $row) {
                    $timezone ??= self::value($row, 'agency_timezone');
                    yield [$name, $row['agency_id'] ?? '' ?: '_', self::value($row, 'agency_name'), self::value($row, 'agency_url'), self::value($row, 'agency_timezone'), self::value($row, 'agency_phone')];
                }
            })()));

            $box = [90.0, 180.0, -90.0, -180.0];
            $tell('stops.txt', $this->store->insert('gtfs_stop', ['feed', 'stop_id', 'code', 'name', 'search', 'lat', 'lon', 'location_type', 'parent', 'platform', 'timezone', 'wheelchair'], (function () use ($feed, $name, &$box, $keep) {
                foreach ($feed->rows('stops.txt') as $row) {
                    if (null !== $keep && !isset($keep['stops'][$row['stop_id'] ?? ''])) {
                        continue;
                    }
                    $lat = is_numeric($row['stop_lat'] ?? null) ? Cast::float($row['stop_lat']) : null;
                    $lon = is_numeric($row['stop_lon'] ?? null) ? Cast::float($row['stop_lon']) : null;
                    $type = Cast::int($row['location_type'] ?? 0);
                    if (null !== $lat && null !== $lon && $type <= 1 && (0.0 !== $lat || 0.0 !== $lon)) {
                        $box = [min($box[0], $lat), min($box[1], $lon), max($box[2], $lat), max($box[3], $lon)];
                    }
                    $stopName = self::value($row, 'stop_name') ?? self::value($row, 'stop_id') ?? '';
                    yield [$name, $row['stop_id'] ?? '', self::value($row, 'stop_code'), $stopName, Text::searchable($stopName), $lat, $lon, $type, self::value($row, 'parent_station'), self::value($row, 'platform_code'), self::value($row, 'stop_timezone'), self::nullableInt($row, 'wheelchair_boarding')];
                }
            })()));

            $tell('routes.txt', $this->store->insert('gtfs_route', ['feed', 'route_id', 'agency_id', 'short_name', 'long_name', 'type', 'color', 'text_color', 'url', 'sort_order'], (function () use ($feed, $name, $keep) {
                foreach ($feed->rows('routes.txt') as $row) {
                    if (null !== $keep && !isset($keep['routes'][$row['route_id'] ?? ''])) {
                        continue;
                    }
                    yield [$name, $row['route_id'] ?? '', self::value($row, 'agency_id') ?? '_', self::value($row, 'route_short_name'), self::value($row, 'route_long_name'), Cast::int($row['route_type'] ?? 3), self::value($row, 'route_color'), self::value($row, 'route_text_color'), self::value($row, 'route_url'), self::nullableInt($row, 'route_sort_order')];
                }
            })()));

            $shapes = [];
            $tell('trips.txt', $this->store->insert('gtfs_trip', ['feed', 'trip_id', 'route_id', 'service_id', 'headsign', 'short_name', 'direction_id', 'shape_id'], (function () use ($feed, $name, $keep, &$shapes) {
                foreach ($feed->rows('trips.txt') as $row) {
                    if (null !== $keep && !isset($keep['trips'][$row['trip_id'] ?? ''])) {
                        continue;
                    }
                    $shape = self::value($row, 'shape_id');
                    if (null !== $shape) {
                        $shapes[$shape] = true;
                    }
                    yield [$name, $row['trip_id'] ?? '', $row['route_id'] ?? '', $row['service_id'] ?? '', self::value($row, 'trip_headsign'), self::value($row, 'trip_short_name'), self::nullableInt($row, 'direction_id'), $shape];
                }
            })()));

            // The trips' shapes, points in order with the distance travelled when the feed gives it.
            $tell('shapes.txt', $this->store->insert('gtfs_shape', ['feed', 'shape_id', 'seq', 'lat', 'lon', 'dist'], (function () use ($feed, $name, &$shapes) {
                if ([] === $shapes || !$feed->has('shapes.txt')) {
                    return;
                }
                $seen = [];
                foreach ($feed->rows('shapes.txt') as $row) {
                    $shape = trim($row['shape_id'] ?? '');
                    if (!isset($shapes[$shape]) || !is_numeric($row['shape_pt_lat'] ?? null) || !is_numeric($row['shape_pt_lon'] ?? null)) {
                        continue;
                    }
                    $seq = Cast::int($row['shape_pt_sequence'] ?? 0);
                    if (isset($seen[$shape][$seq])) {
                        continue;
                    }
                    $seen[$shape][$seq] = true;
                    $dist = trim($row['shape_dist_traveled'] ?? '');
                    yield [$name, $shape, $seq, Cast::float($row['shape_pt_lat']), Cast::float($row['shape_pt_lon']), is_numeric($dist) ? (float) $dist : null];
                }
            })()));

            $tell('calendar.txt', $this->store->insert('gtfs_calendar', ['feed', 'service_id', 'days', 'start_date', 'end_date'], (function () use ($feed, $name, $keep) {
                foreach ($feed->rows('calendar.txt') as $row) {
                    if (null !== $keep && !isset($keep['services'][$row['service_id'] ?? ''])) {
                        continue;
                    }
                    $days = 0;
                    foreach (self::DAYS as $bit => $day) {
                        $days |= '1' === ($row[$day] ?? '0') ? 1 << $bit : 0;
                    }
                    yield [$name, $row['service_id'] ?? '', $days, Cast::int($row['start_date'] ?? 0), Cast::int($row['end_date'] ?? 0)];
                }
            })()));

            $tell('calendar_dates.txt', $this->store->insert('gtfs_calendar_date', ['feed', 'service_id', 'date', 'type'], (function () use ($feed, $name, $keep) {
                $seen = [];
                foreach ($feed->rows('calendar_dates.txt') as $row) {
                    if (null !== $keep && !isset($keep['services'][$row['service_id'] ?? ''])) {
                        continue;
                    }
                    $key = ($row['service_id'] ?? '').'|'.($row['date'] ?? '');
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        yield [$name, $row['service_id'] ?? '', Cast::int($row['date'] ?? 0), Cast::int($row['exception_type'] ?? 0)];
                    }
                }
            })()));

            // Each trip's first and last stop, read before the rows are written: a departure is never a trip's last call, an arrival never its first.
            $bounds = $keep['bounds'] ?? self::bounds($feed, null);
            $tell('stop_times.txt', $this->store->insert('gtfs_stop_time', ['feed', 'trip_id', 'seq', 'stop_id', 'arrival', 'departure', 'headsign', 'pickup', 'drop_off', 'is_first', 'is_last'], (function () use ($feed, $name, $bounds, $keep) {
                foreach ($feed->rows('stop_times.txt') as $row) {
                    $trip = $row['trip_id'] ?? '';
                    if (null !== $keep && !isset($bounds[$trip])) {
                        continue;
                    }
                    $seq = Cast::int($row['stop_sequence'] ?? 0);
                    $arrival = self::seconds($row['arrival_time'] ?? '');
                    $departure = self::seconds($row['departure_time'] ?? '');
                    yield [$name, $trip, $seq, $row['stop_id'] ?? '', $arrival ?? $departure, $departure ?? $arrival, self::value($row, 'stop_headsign'), Cast::int($row['pickup_type'] ?? 0), Cast::int($row['drop_off_type'] ?? 0), (int) ($seq === ($bounds[$trip][0] ?? null)), (int) ($seq === ($bounds[$trip][1] ?? null))];
                }
            })()));
            unset($bounds);

            $tell('route stops', $this->routeStops($name));
            $stopRoutes = $this->store->pdo()->prepare('INSERT INTO gtfs_stop_route (feed, stop_id, route_id) SELECT DISTINCT st.feed, st.stop_id, t.route_id FROM gtfs_stop_time st JOIN gtfs_trip t ON t.feed = st.feed AND t.trip_id = st.trip_id WHERE st.feed = ?');
            $stopRoutes->execute([$name]);
            $tell('stop routes', $stopRoutes->rowCount());

            $info = [];
            foreach ($feed->rows('feed_info.txt') as $row) {
                $info = $row;
                break;
            }
            $valid = $box[0] <= $box[2];
            $this->store->insert('gtfs_feed', ['feed', 'title', 'timezone', 'publisher', 'url', 'version', 'lang', 'start_date', 'end_date', 'imported_at', 'south', 'west', 'north', 'east', 'stops', 'trips', 'stop_times'], [[
                $name, $meta['title'] ?? self::value($info, 'feed_publisher_name') ?? $name, $timezone, self::value($info, 'feed_publisher_name'), $meta['url'] ?? self::value($info, 'feed_publisher_url'),
                self::value($info, 'feed_version'), self::value($info, 'feed_lang'), self::nullableInt($info, 'feed_start_date'), self::nullableInt($info, 'feed_end_date'), time(),
                $valid ? $box[0] : null, $valid ? $box[1] : null, $valid ? $box[2] : null, $valid ? $box[3] : null,
                $counts['stops.txt'] ?? 0, $counts['trips.txt'] ?? 0, $counts['stop_times.txt'] ?? 0,
            ]]);

            return $counts;
        });

        return new ImportReport($name, $counts, microtime(true) - $started);
    }

    /**
     * What a filter keeps: the routes it accepts, their trips, the services
     * those run on, the stops they call at with their stations, and each
     * trip's first and last sequence - read before anything is written.
     *
     * @return array{routes: array<string, true>, trips: array<string, true>, services: array<string, true>, stops: array<string, true>, bounds: array<string, array{0: int, 1: int}>}
     */
    private function choose(Feed $feed, FeedFilter $filter): array
    {
        $routes = $trips = $services = $stops = [];
        foreach ($feed->rows('routes.txt') as $row) {
            if ($filter->accepts($row)) {
                $routes[$row['route_id'] ?? ''] = true;
            }
        }
        foreach ($feed->rows('trips.txt') as $row) {
            if (isset($routes[$row['route_id'] ?? ''])) {
                $trips[$row['trip_id'] ?? ''] = true;
                $services[$row['service_id'] ?? ''] = true;
            }
        }
        $bounds = self::bounds($feed, $trips, $stops);
        // The stations of the stops called at, and theirs in turn.
        $parents = [];
        foreach ($feed->rows('stops.txt') as $row) {
            $parent = trim($row['parent_station'] ?? '');
            if ('' !== $parent) {
                $parents[$row['stop_id'] ?? ''] = $parent;
            }
        }
        foreach (array_keys($stops) as $stop) {
            for ($id = (string) $stop, $depth = 0; isset($parents[$id]) && $depth < 4; ++$depth) {
                $id = $parents[$id];
                $stops[$id] = true;
            }
        }

        return ['routes' => $routes, 'trips' => $trips, 'services' => $services, 'stops' => $stops, 'bounds' => $bounds];
    }

    /**
     * Each trip's first and last sequence (of the trips given, every one by default), and the stops they call at.
     *
     * @param ?array<string, true> $trips
     * @param array<string, true>  $stops
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function bounds(Feed $feed, ?array $trips, array &$stops = []): array
    {
        $bounds = [];
        foreach ($feed->rows('stop_times.txt') as $row) {
            $trip = $row['trip_id'] ?? '';
            if (null !== $trips && !isset($trips[$trip])) {
                continue;
            }
            $seq = Cast::int($row['stop_sequence'] ?? 0);
            $bounds[$trip] = isset($bounds[$trip]) ? [min($bounds[$trip][0], $seq), max($bounds[$trip][1], $seq)] : [$seq, $seq];
            if (null !== $trips) {
                $stops[$row['stop_id'] ?? ''] = true;
            }
        }

        return $bounds;
    }

    /** "25:10:05" -> 90605; null when empty or unreadable. */
    public static function seconds(string $time): ?int
    {
        if (!preg_match('~^\s*(\d{1,3}):(\d{2}):(\d{2})\s*$~', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    /** Each route's stops per direction, from the trip that calls at the most of them. */
    private function routeStops(string $name): int
    {
        $longest = [];
        foreach ($this->store->rows('SELECT t.route_id, t.direction_id, st.trip_id, COUNT(*) AS n FROM gtfs_stop_time st JOIN gtfs_trip t ON t.feed = st.feed AND t.trip_id = st.trip_id WHERE st.feed = ? GROUP BY t.route_id, t.direction_id, st.trip_id', [$name]) as $row) {
            $key = Cast::string($row['route_id']).'|'.Cast::int($row['direction_id'] ?? 0);
            if (!isset($longest[$key]) || Cast::int($row['n']) > $longest[$key][1]) {
                $longest[$key] = [Cast::string($row['trip_id']), Cast::int($row['n'])];
            }
        }
        $rows = [];
        foreach ($longest as $key => [$trip]) {
            [$route, $direction] = explode('|', $key, 2);
            foreach ($this->store->rows('SELECT stop_id FROM gtfs_stop_time WHERE feed = ? AND trip_id = ? ORDER BY seq', [$name, $trip]) as $seq => $stop) {
                $rows[] = [$name, $route, $direction, $seq, Cast::string($stop['stop_id'])];
            }
        }

        return $this->store->insert('gtfs_route_stop', ['feed', 'route_id', 'direction', 'seq', 'stop_id'], $rows);
    }

    /** @param array<string, string> $row */
    private static function value(array $row, string $key): ?string
    {
        $value = trim($row[$key] ?? '');

        return '' === $value ? null : $value;
    }

    /** @param array<string, string> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $value = trim($row[$key] ?? '');

        return is_numeric($value) ? (int) $value : null;
    }
}

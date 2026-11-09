<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Capabilities;
use Omnitravel\Exception\NotSupportedException;
use Omnitravel\Exception\OmnitravelException;
use Omnitravel\Gtfs\Estimate\Call;
use Omnitravel\Gtfs\Estimate\EstimatedVehicle;
use Omnitravel\Gtfs\Estimate\PositionEstimator;
use Omnitravel\Gtfs\Realtime\FeedMessage;
use Omnitravel\Gtfs\Realtime\TripUpdate;
use Omnitravel\Gtfs\Siri\SiriLite;
use Omnitravel\Gtfs\Siri\Visit;
use Omnitravel\Http\JsonClient;
use Omnitravel\Model\BoundingBox;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Operator;
use Omnitravel\Model\Stop;
use Omnitravel\Model\StopKind;
use Omnitravel\Model\VehiclePosition;
use Omnitravel\Model\VehicleQuery;
use Omnitravel\NetworkInterface;
use Omnitravel\TimetablesInterface;
use Omnitravel\TrackingInterface;

/**
 * One GTFS feed, imported into a Store: its stops near a point or by name,
 * its lines and their stops in order, a stop's next departures and arrivals
 * from the feed's calendars, and - where the network publishes
 * GTFS-Realtime - the delays, cancellations and skipped stops of its trip
 * updates laid over them, and its vehicles' positions.
 *
 * Times are read in the feed's time zone (its agency's), from each service
 * day's "noon minus twelve hours", as GTFS defines them: a trip of
 * yesterday's service leaving at 25:10 leaves today at 01:10.
 */
final class GtfsProvider implements TimetablesInterface, TrackingInterface, NetworkInterface
{
    /** How far back calls are read when real-time data may have delayed them past the query's moment. */
    private const LOOKBACK = 1800;

    /** @var ?array<string, mixed> */
    private ?array $feedRow = null;

    /** @var ?array<string, Line> */
    private ?array $lines = null;

    /** @var array<int, array<string, true>> active services by YYYYMMDD */
    private array $services = [];

    /** @var array<string, array{0: int, 1: ?FeedMessage}> fetched feeds by URL: when, and what */
    private array $realtime = [];

    private ?string $realtimeError = null;

    /**
     * @param list<string> $countries
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $feed,
        private readonly ?JsonClient $http = null,
        private readonly ?string $tripUpdatesUrl = null,
        private readonly ?string $vehiclePositionsUrl = null,
        private readonly int $realtimeTtl = 30,
        private readonly ?string $realtimeCache = null,
        private readonly ?string $timezone = null,
        private readonly ?string $title = null,
        private readonly ?string $attribution = null,
        private readonly ?string $licence = null,
        private readonly array $countries = [],
        private readonly string $name = 'gtfs',
        private readonly ?string $alertsUrl = null,
        private readonly ?SiriLite $siri = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title ?? Cast::string($this->feedRow()['title'] ?? $this->feed);
    }

    public function feed(): string
    {
        return $this->feed;
    }

    public function capabilities(): Capabilities
    {
        $row = $this->feedRow();
        $area = isset($row['south'], $row['west'], $row['north'], $row['east']) ? new BoundingBox(Cast::float($row['south']), Cast::float($row['west']), Cast::float($row['north']), Cast::float($row['east'])) : null;
        $modes = array_values(array_unique(array_map(static fn (Line $line) => $line->mode, $this->allLines()), \SORT_REGULAR));

        return new Capabilities(
            timetables: true, arrivals: true, realtime: null !== $this->tripUpdatesUrl || true === $this->siri?->monitorsStops(), tracking: null !== $this->vehiclePositionsUrl,
            network: true, nearby: true, stopSearch: true, lines: true, platforms: true,
            modes: $modes, countries: $this->countries, area: $area, attribution: $this->attribution, licence: $this->licence, keyless: true,
        );
    }

    /** The feed as imported: its title, publisher, validity, size, when; null before its first import. */
    public function info(): ?FeedInfo
    {
        $row = $this->feedRow();

        return [] === $row ? null : FeedInfo::fromRow($row);
    }

    /**
     * The service alerts in force at a moment (now by default) that concern
     * a board: one of the lines, one of the stops (a station and its
     * platforms are one), the whole network; every one in force when
     * neither lines nor stops are given. Read from the network's
     * GTFS-Realtime Alerts feed (alerts_url) and from the alerts a combined
     * feed carries beside its trip updates.
     *
     * @param list<string> $lines GTFS route identifiers
     * @param list<string> $stops GTFS stop identifiers
     *
     * @return list<ServiceAlert>
     */
    public function alerts(array $lines = [], array $stops = [], ?\DateTimeImmutable $at = null): array
    {
        $found = [];
        foreach (array_unique(array_filter([$this->alertsUrl, $this->tripUpdatesUrl])) as $url) {
            foreach ($this->realtimeFeed($url)->alerts ?? [] as $alert) {
                $found[$alert->id.'|'.$alert->headerText()] = $alert;
            }
        }
        if (null !== $this->siri && $this->siri->monitorsMessages() && [] !== $lines) {
            try {
                foreach ($this->siri->messages($lines) as $alert) {
                    $found[$alert->id.'|'.$alert->headerText()] = $alert;
                }
            } catch (OmnitravelException $e) {
                $this->realtimeError = $e->getMessage();
            }
        }
        $moment = ($at ?? new \DateTimeImmutable())->getTimestamp();
        $stops = $this->stopFamily($stops);

        return array_values(array_filter($found, static fn (ServiceAlert $alert) => $alert->activeAt($moment) && (([] === $lines && [] === $stops) || $alert->concerns($lines, $stops))));
    }

    /** Why the last real-time read failed, null when it did not: departures then come from the timetable alone. */
    public function realtimeError(): ?string
    {
        return $this->realtimeError;
    }

    public function departures(DepartureQuery $query): array
    {
        $stops = $this->stopsCalledAt($query->stop);
        if ([] === $stops) {
            return [];
        }
        $timezone = $this->timeZone();
        $from = $query->from()->setTimezone($timezone);
        $fromTs = $from->getTimestamp();
        $endTs = null === $query->window ? null : $fromTs + $query->window;
        $updates = null !== $this->tripUpdatesUrl ? $this->tripUpdates() : [];
        $lookback = [] === $updates ? 0 : self::LOOKBACK;
        [$time, $edge, $boarding] = $query->arrivals ? ['arrival', 'is_first', 'drop_off'] : ['departure', 'is_last', 'pickup'];
        $lines = $this->allLines();
        $ids = array_keys($stops);
        $want = $query->limit + ([] === $query->modes ? 5 : 3 * $query->limit);

        $found = [];
        for ($offset = -1; $offset <= 2; ++$offset) {
            $day = $from->setTime(12, 0)->modify(\sprintf('%+d day', $offset));
            $base = $day->getTimestamp() - 43200;
            $lower = $fromTs - $lookback - $base;
            if ($lower > 172800) {
                continue;
            }
            if (null !== $endTs && $base > $endTs) {
                break;
            }
            $services = array_keys($this->activeServices($day));
            if ([] === $services) {
                continue;
            }
            $parameters = [$this->feed, ...$ids, max(0, $lower)];
            $sql = "SELECT st.trip_id, st.stop_id, st.seq, st.$time AS t, st.headsign AS stop_headsign, tr.route_id, tr.headsign, tr.short_name, tr.direction_id
                FROM gtfs_stop_time st JOIN gtfs_trip tr ON tr.feed = st.feed AND tr.trip_id = st.trip_id
                WHERE st.feed = ? AND st.stop_id IN (".Store::marks($ids).") AND st.$time >= ? AND st.$edge = 0 AND st.$boarding <> 1";
            if (null !== $endTs) {
                $sql .= " AND st.$time <= ?";
                $parameters[] = $endTs - $base;
            }
            if ([] !== $query->lines) {
                $sql .= ' AND tr.route_id IN ('.Store::marks($query->lines).')';
                array_push($parameters, ...$query->lines);
            }
            $sql .= ' AND tr.service_id IN ('.Store::marks($services).") ORDER BY st.$time LIMIT ".(int) $want;
            array_push($parameters, ...$services);

            foreach ($this->store->rows($sql, $parameters) as $row) {
                $line = $lines[Cast::string($row['route_id'])] ?? null;
                $scheduled = (new \DateTimeImmutable('@'.($base + Cast::int($row['t']))))->setTimezone($timezone);
                $departure = new Departure(
                    $stops[Cast::string($row['stop_id'])],
                    $line,
                    Cast::string($row['stop_headsign'] ?? $row['headsign'] ?? $line->longName ?? ''),
                    $scheduled,
                    $this->name,
                    platform: $stops[Cast::string($row['stop_id'])]->platform,
                    tripId: Cast::string($row['trip_id']),
                    number: null === $row['short_name'] ? null : Cast::string($row['short_name']),
                    arrival: $query->arrivals,
                );
                $departure = $this->withRealtime($departure, $updates[Cast::string($row['trip_id'])] ?? [], Cast::int($row['seq']), $day->format('Ymd'), $query->arrivals);
                if ($departure->time()->getTimestamp() >= $fromTs && (null === $endTs || $departure->time()->getTimestamp() <= $endTs) && $query->accepts($departure)) {
                    $found[] = $departure;
                }
            }
            if ($offset >= 0 && \count($found) >= $query->limit) {
                break;
            }
        }
        if (null !== $this->siri && $this->siri->monitorsStops() && !$query->arrivals) {
            $found = $this->withSiri($found, $query, $stops, $fromTs, $endTs);
        }

        return \array_slice(Departure::sorted($found), 0, $query->limit);
    }

    public function vehicles(VehicleQuery $query): array
    {
        if (null === $this->vehiclePositionsUrl) {
            throw NotSupportedException::call($this->getTitle(), 'publish its vehicles\' positions (no vehicle_positions_url)');
        }
        $feed = $this->realtimeFeed($this->vehiclePositionsUrl);
        if (null === $feed) {
            return [];
        }
        $lines = $this->allLines();
        $trips = $this->tripRoutes(array_values(array_filter(array_map(static fn ($v) => null === $v->trip->routeId ? $v->trip->tripId : null, $feed->vehicles))));
        $positions = [];
        foreach ($feed->vehicles as $vehicle) {
            if (null === $vehicle->latitude || null === $vehicle->longitude || (0.0 === $vehicle->latitude && 0.0 === $vehicle->longitude)) {
                continue;
            }
            $routeId = $vehicle->trip->routeId ?? $trips[(string) $vehicle->trip->tripId] ?? null;
            $line = null === $routeId ? null : $lines[$routeId] ?? null;
            $position = new VehiclePosition(
                $vehicle->vehicle ?? (string) $vehicle->trip->tripId,
                new Coordinates($vehicle->latitude, $vehicle->longitude),
                new \DateTimeImmutable('@'.($vehicle->timestamp ?? $feed->timestamp ?? time())),
                $this->name, $vehicle->trip->tripId, $line, $routeId, $vehicle->bearing, $vehicle->speed,
                status: $vehicle->status, stopId: $vehicle->stopId, label: $vehicle->label, mode: $line?->mode,
            );
            if ($query->accepts($position)) {
                $positions[] = $position;
            }
        }

        return \array_slice($positions, 0, $query->limit);
    }

    /**
     * Where a line's vehicles are estimated to be at a moment (now by
     * default), for networks that publish no positions: each trip of the
     * line running then - from the services of the day and of the day
     * before - its calls with the times its trip update predicts (or the
     * timetable's plus the last delay it gave), placed by PositionEstimator
     * along its shape. A trip cancelled by real time is left out.
     *
     * @return list<EstimatedVehicle>
     */
    public function estimatedVehicles(string $lineId, ?\DateTimeImmutable $at = null): array
    {
        $now = ($at ?? new \DateTimeImmutable())->getTimestamp();
        $timezone = $this->timeZone();
        $updates = null !== $this->tripUpdatesUrl ? $this->tripUpdates() : [];
        $line = $this->allLines()[$lineId] ?? null;
        $shapes = [];
        $vehicles = [];
        foreach ([-1, 0] as $offset) {
            $day = (new \DateTimeImmutable('@'.$now))->setTimezone($timezone)->setTime(12, 0)->modify(\sprintf('%+d day', $offset));
            $base = $day->getTimestamp() - 43200;
            $services = array_keys($this->activeServices($day));
            if ([] === $services) {
                continue;
            }
            $moment = $now - $base;
            // Running then by the timetable, or up to half an hour late.
            $trips = $this->store->rows('SELECT t.trip_id, t.headsign, t.direction_id, t.shape_id FROM gtfs_trip t
                JOIN gtfs_stop_time f ON f.feed = t.feed AND f.trip_id = t.trip_id AND f.is_first = 1
                JOIN gtfs_stop_time l ON l.feed = t.feed AND l.trip_id = t.trip_id AND l.is_last = 1
                WHERE t.feed = ? AND t.route_id = ? AND f.departure <= ? AND l.arrival >= ? AND t.service_id IN ('.Store::marks($services).')', [$this->feed, $lineId, $moment + 120, $moment - self::LOOKBACK, ...$services]);
            foreach ($trips as $trip) {
                $tripId = Cast::string($trip['trip_id']);
                $update = null;
                foreach ($updates[$tripId] ?? [] as $candidate) {
                    if (null === $candidate->startDate || $candidate->startDate === $day->format('Ymd')) {
                        $update = $candidate;
                        break;
                    }
                }
                if (null !== $update && $update->cancelled) {
                    continue;
                }
                $calls = $delays = [];
                $delay = null;
                $moved = false;
                foreach ($this->store->rows('SELECT st.seq, st.stop_id, st.arrival, st.departure, s.lat, s.lon FROM gtfs_stop_time st JOIN gtfs_stop s ON s.feed = st.feed AND s.stop_id = st.stop_id WHERE st.feed = ? AND st.trip_id = ? ORDER BY st.seq', [$this->feed, $tripId]) as $row) {
                    if (!is_numeric($row['lat'] ?? null) || !is_numeric($row['lon'] ?? null)) {
                        continue;
                    }
                    $arrival = $base + Cast::int($row['arrival']);
                    $departure = $base + Cast::int($row['departure']);
                    $call = $update?->at(Cast::int($row['seq']), Cast::string($row['stop_id']));
                    if (null !== $call && $call->skipped) {
                        continue;
                    }
                    if (null !== $call) {
                        $delay = $call->delay($arrival, true) ?? $delay;
                        $moved = true;
                    }
                    $tripDelay = null === $update ? null : $update->delay;
                    $arrivalDelay = null === $call ? ($delay ?? $tripDelay ?? 0) : ($call->delay($arrival, true) ?? $delay ?? 0);
                    $departureDelay = null === $call ? ($delay ?? $tripDelay ?? 0) : ($call->delay($departure) ?? $arrivalDelay);
                    $delays[Cast::string($row['stop_id'])] = $arrivalDelay;
                    $calls[] = new Call(Cast::string($row['stop_id']), new Coordinates((float) $row['lat'], (float) $row['lon']), $arrival + $arrivalDelay, max($arrival + $arrivalDelay, $departure + $departureDelay));
                }
                $shapeId = self::text($trip['shape_id'] ?? null);
                $shape = null === $shapeId ? [] : ($shapes[$shapeId] ??= $this->shapePoints($shapeId));
                $estimate = PositionEstimator::estimate($calls, $now, $shape);
                if (null === $estimate) {
                    continue;
                }
                $vehicles[] = new EstimatedVehicle(
                    $tripId, $line, Cast::string($trip['headsign'] ?? (null === $line ? null : $line->longName) ?? ''),
                    null === ($trip['direction_id'] ?? null) ? null : Cast::int($trip['direction_id']),
                    $estimate, $moved || null !== $update?->delay, $moved || null !== $update?->delay ? ($delays[$estimate->to ?? $estimate->from] ?? $delay) : null,
                    $update?->timestamp,
                );
            }
        }

        return $vehicles;
    }

    /**
     * A trip's shape: its points in order as [latitude, longitude]; none when the feed gives none.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function shape(string $tripId): array
    {
        try {
            $row = $this->store->row('SELECT shape_id FROM gtfs_trip WHERE feed = ? AND trip_id = ?', [$this->feed, $tripId]);
        } catch (\PDOException) {
            return [];
        }
        $shape = null === $row ? null : self::text($row['shape_id'] ?? null);

        return null === $shape ? [] : $this->shapePoints($shape);
    }

    public function stop(string $id): ?Stop
    {
        $row = $this->store->row('SELECT * FROM gtfs_stop WHERE feed = ? AND stop_id = ?', [$this->feed, $id]);

        return null === $row ? null : $this->stopOf($row)->withLines($this->stopLines($id));
    }

    public function nearby(Coordinates $point, int $radius = 500, int $limit = 20): array
    {
        $box = BoundingBox::around($point, $radius);
        $rows = $this->store->rows("SELECT * FROM gtfs_stop WHERE feed = ? AND lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? AND location_type IN (0, 1) AND (parent IS NULL OR parent = '')", [$this->feed, $box->south, $box->north, $box->west, $box->east]);

        return $this->withLines(Stop::nearest(array_map($this->stopOf(...), $rows), $point, $radius, $limit));
    }

    public function searchStops(string $text, int $limit = 20, ?Coordinates $near = null): array
    {
        $words = Text::words($text);
        if ([] === $words) {
            return [];
        }
        $sql = "SELECT * FROM gtfs_stop WHERE feed = ? AND location_type IN (0, 1) AND (parent IS NULL OR parent = '')";
        $parameters = [$this->feed];
        foreach ($words as $word) {
            $sql .= ' AND search LIKE ?';
            $parameters[] = '%'.addcslashes($word, '%_').'%';
        }
        $stops = array_map($this->stopOf(...), $this->store->rows($sql.' LIMIT 500', $parameters));
        $needle = implode(' ', $words);
        $rank = static function (Stop $stop) use ($needle): int {
            $name = Text::searchable($stop->name);

            return $name === $needle ? 0 : (str_starts_with($name, $needle) ? 1 : (str_contains(' '.$name, ' '.$needle) ? 2 : 3));
        };
        if (null !== $near) {
            $stops = array_map(static fn (Stop $stop) => $stop->from($near), $stops);
        }
        usort($stops, static fn (Stop $a, Stop $b) => [$rank($a), $a->distance ?? 0, $a->name, $a->id] <=> [$rank($b), $b->distance ?? 0, $b->name, $b->id]);

        return $this->withLines(\array_slice($stops, 0, max(0, $limit)));
    }

    public function lines(): array
    {
        return array_values($this->allLines());
    }

    public function line(string $id): ?Line
    {
        return $this->allLines()[$id] ?? null;
    }

    public function lineStops(string $lineId, ?string $direction = null): array
    {
        $directions = array_map(static fn (array $row) => Cast::string($row['direction']), $this->store->rows('SELECT DISTINCT direction FROM gtfs_route_stop WHERE feed = ? AND route_id = ? ORDER BY direction', [$this->feed, $lineId]));
        $direction ??= $directions[0] ?? '0';
        $rows = $this->store->rows('SELECT s.* FROM gtfs_route_stop rs JOIN gtfs_stop s ON s.feed = rs.feed AND s.stop_id = rs.stop_id WHERE rs.feed = ? AND rs.route_id = ? AND rs.direction = ? ORDER BY rs.seq', [$this->feed, $lineId, $direction]);

        return array_map($this->stopOf(...), $rows);
    }

    /** @return list<string> the directions a line's stops are kept for ("0", "1") */
    public function lineDirections(string $lineId): array
    {
        return array_map(static fn (array $row) => Cast::string($row['direction']), $this->store->rows('SELECT DISTINCT direction FROM gtfs_route_stop WHERE feed = ? AND route_id = ? ORDER BY direction', [$this->feed, $lineId]));
    }

    public function stopLines(string $stopId): array
    {
        return $this->linesAt([$stopId])[$stopId] ?? [];
    }

    public function operators(): array
    {
        return array_map(fn (array $row) => $this->operatorOf($row), $this->store->rows('SELECT * FROM gtfs_agency WHERE feed = ? ORDER BY name', [$this->feed]));
    }

    public function timeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone ?? Cast::string($this->feedRow()['timezone'] ?? 'UTC'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * The services running on a day (calendar's weekdays and dates, then
     * calendar_dates' additions and removals).
     *
     * @return array<string, true>
     */
    public function activeServices(\DateTimeImmutable $day): array
    {
        $date = (int) $day->format('Ymd');
        if (isset($this->services[$date])) {
            return $this->services[$date];
        }
        $active = [];
        foreach ($this->store->rows('SELECT service_id FROM gtfs_calendar WHERE feed = ? AND start_date <= ? AND end_date >= ? AND (days & ?) <> 0', [$this->feed, $date, $date, 1 << ((int) $day->format('N') - 1)]) as $row) {
            $active[Cast::string($row['service_id'])] = true;
        }
        foreach ($this->store->rows('SELECT service_id, type FROM gtfs_calendar_date WHERE feed = ? AND date = ?', [$this->feed, $date]) as $row) {
            if (1 === Cast::int($row['type'])) {
                $active[Cast::string($row['service_id'])] = true;
            } else {
                unset($active[Cast::string($row['service_id'])]);
            }
        }

        return $this->services[$date] = $active;
    }

    /**
     * The stop asked and, for a station, the stops it groups, by identifier.
     *
     * @return array<string, Stop>
     */
    private function stopsCalledAt(string $id): array
    {
        $stops = [];
        foreach ($this->store->rows('SELECT * FROM gtfs_stop WHERE feed = ? AND (stop_id = ? OR parent = ?)', [$this->feed, $id, $id]) as $row) {
            $stops[Cast::string($row['stop_id'])] = $this->stopOf($row);
        }

        return isset($stops[$id]) ? $stops : [];
    }

    /**
     * SIRI's StopMonitoring laid over the timetable: for each line SIRI
     * announces at the stop, its visits replace the line's scheduled calls
     * up to the last one announced (a minute's margin); the timetable goes
     * on after it. A visit's time is SIRI's expected one, its scheduled time
     * SIRI's aimed one when given (otherwise the expected one: no delay is
     * made up). When SIRI cannot be read, the timetable stays and
     * realtimeError() says why.
     *
     * @param list<Departure>      $found
     * @param array<string, Stop>  $stops the stop asked and, for a station, its platforms
     *
     * @return list<Departure>
     */
    private function withSiri(array $found, DepartureQuery $query, array $stops, int $fromTs, ?int $endTs): array
    {
        \assert(null !== $this->siri);
        $root = $stops[$query->stop];
        $station = StopKind::Station === $root->kind;
        try {
            $visits = $this->siri->visits($query->stop, $station, array_values(array_filter(array_map('strval', array_keys($stops)), static fn (string $id) => $id !== $query->stop)));
        } catch (OmnitravelException $e) {
            $this->realtimeError = $e->getMessage();

            return $found;
        }
        $lines = $this->allLines();
        // By line and platform: SIRI may answer for one platform of a station and not the other.
        $groups = [];
        foreach ($visits as $visit) {
            if (null !== $visit->line && null !== $visit->time()) {
                $stop = isset($stops[(string) $visit->stop]) ? (string) $visit->stop : null;
                $groups[$visit->line.'|'.$stop][] = $visit;
            }
        }
        $timezone = $this->timeZone();
        foreach ($groups as $announced) {
            $line = (string) $announced[0]->line;
            $platform = isset($stops[(string) $announced[0]->stop]) ? (string) $announced[0]->stop : null;
            $horizon = max(array_map(static fn (Visit $visit) => (int) $visit->time(), $announced));
            $found = array_values(array_filter($found, static fn (Departure $d) => $d->line?->id !== $line || (null !== $platform && $d->stop->id !== $platform) || $d->time()->getTimestamp() > $horizon + 60));
            foreach ($announced as $visit) {
                $time = (int) $visit->time();
                $stop = null === $platform ? $root : $stops[$platform];
                $departure = new Departure(
                    $stop, $lines[$line] ?? null, $visit->destination,
                    (new \DateTimeImmutable('@'.($visit->aimed ?? $time)))->setTimezone($timezone), $this->name,
                    (new \DateTimeImmutable('@'.$time))->setTimezone($timezone), true, $visit->cancelled,
                    $visit->platform ?? $stop->platform, tripId: $visit->journey,
                );
                if (($time >= $fromTs || $visit->atStop) && (null === $endTs || $time <= $endTs) && $query->accepts($departure)) {
                    $found[] = $departure;
                }
            }
        }

        return $found;
    }

    /**
     * Stops with their station and the station's other platforms: an alert
     * on a station concerns each of its platforms, and the other way round.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function stopFamily(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $family = array_fill_keys($ids, true);
        foreach ($this->store->rows('SELECT stop_id, parent FROM gtfs_stop WHERE feed = ? AND (stop_id IN ('.Store::marks($ids).') OR parent IN ('.Store::marks($ids).'))', [$this->feed, ...$ids, ...$ids]) as $row) {
            $family[Cast::string($row['stop_id'])] = true;
            if (null !== $parent = self::text($row['parent'] ?? null)) {
                $family[$parent] = true;
            }
        }

        return array_map('strval', array_keys($family));
    }

    /** @return list<array{0: float, 1: float}> */
    private function shapePoints(string $shape): array
    {
        try {
            $rows = $this->store->rows('SELECT lat, lon FROM gtfs_shape WHERE feed = ? AND shape_id = ? ORDER BY seq', [$this->feed, $shape]);
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $row) => [Cast::float($row['lat']), Cast::float($row['lon'])], $rows);
    }

    /** @param list<TripUpdate> $updates */
    private function withRealtime(Departure $departure, array $updates, int $sequence, string $serviceDay, bool $arrival): Departure
    {
        foreach ($updates as $update) {
            if (null !== $update->startDate && $serviceDay !== $update->startDate) {
                continue;
            }
            $cancelled = $update->cancelled;
            $delay = null;
            if (!$cancelled) {
                $call = $update->at($sequence, $departure->stop->id);
                if (null !== $call && $call->skipped && ($call->sequence === $sequence || $call->stopId === $departure->stop->id)) {
                    $cancelled = true;
                } elseif (null !== $call) {
                    $delay = $call->delay($departure->scheduled->getTimestamp(), $arrival);
                }
                $delay ??= $update->delay;
            }
            if (!$cancelled && null === $delay) {
                continue;
            }

            return new Departure(
                $departure->stop, $departure->line, $departure->headsign, $departure->scheduled, $departure->source,
                null === $delay ? null : $departure->scheduled->modify(\sprintf('%+d seconds', $delay)),
                true, $cancelled, $departure->platform, tripId: $departure->tripId, number: $departure->number, arrival: $departure->arrival,
            );
        }

        return $departure;
    }

    /** @return array<string, list<TripUpdate>> */
    private function tripUpdates(): array
    {
        return null === $this->tripUpdatesUrl ? [] : ($this->realtimeFeed($this->tripUpdatesUrl)?->byTrip() ?? []);
    }

    private function realtimeFeed(string $url): ?FeedMessage
    {
        $now = time();
        if (isset($this->realtime[$url]) && $now - $this->realtime[$url][0] < $this->realtimeTtl) {
            return $this->realtime[$url][1];
        }
        $file = null === $this->realtimeCache ? null : rtrim($this->realtimeCache, '/').'/'.$this->feed.'-'.substr(sha1($url), 0, 12).'.pb';
        $bytes = null;
        if (null !== $file && is_file($file) && $now - (int) filemtime($file) < $this->realtimeTtl) {
            $bytes = (string) file_get_contents($file);
        }
        try {
            if (null === $bytes) {
                if (null === $this->http) {
                    throw NotSupportedException::call($this->getTitle(), 'read real-time data without an HTTP client');
                }
                $bytes = $this->http->bytes($url) ?? '';
                if (null !== $file) {
                    if (!is_dir(\dirname($file))) {
                        @mkdir(\dirname($file), 0775, true);
                    }
                    $temporary = $file.'.'.getmypid().'.tmp';
                    if (false !== @file_put_contents($temporary, $bytes)) {
                        @rename($temporary, $file);
                    }
                }
            }
            $feed = FeedMessage::parse($bytes);
            $this->realtimeError = null;
        } catch (OmnitravelException $e) {
            $feed = null;
            $this->realtimeError = $e->getMessage();
        }
        $this->realtime[$url] = [$now, $feed];

        return $feed;
    }

    /**
     * @param list<string> $tripIds
     *
     * @return array<string, string> route by trip
     */
    private function tripRoutes(array $tripIds): array
    {
        $routes = [];
        foreach (array_chunk(array_values(array_unique($tripIds)), 500) as $chunk) {
            foreach ($this->store->rows('SELECT trip_id, route_id FROM gtfs_trip WHERE feed = ? AND trip_id IN ('.Store::marks($chunk).')', [$this->feed, ...$chunk]) as $row) {
                $routes[Cast::string($row['trip_id'])] = Cast::string($row['route_id']);
            }
        }

        return $routes;
    }

    /**
     * @param list<Stop> $stops
     *
     * @return list<Stop>
     */
    private function withLines(array $stops): array
    {
        $lines = $this->linesAt(array_map(static fn (Stop $stop) => $stop->id, $stops));

        return array_map(static fn (Stop $stop) => $stop->withLines($lines[$stop->id] ?? []), $stops);
    }

    /**
     * The lines calling at each stop (a station: at the stops it groups), in the lines' order.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<Line>>
     */
    private function linesAt(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $routes = [];
        $rows = $this->store->rows('SELECT COALESCE(s.parent, sr.stop_id) AS parent, sr.stop_id, sr.route_id FROM gtfs_stop_route sr JOIN gtfs_stop s ON s.feed = sr.feed AND s.stop_id = sr.stop_id WHERE sr.feed = ? AND (sr.stop_id IN ('.Store::marks($ids).') OR s.parent IN ('.Store::marks($ids).'))', [$this->feed, ...$ids, ...$ids]);
        foreach ($rows as $row) {
            foreach ([Cast::string($row['stop_id']), Cast::string($row['parent'])] as $id) {
                if (\in_array($id, $ids, true)) {
                    $routes[$id][Cast::string($row['route_id'])] = true;
                }
            }
        }
        $all = $this->allLines();
        $order = array_flip(array_keys($all));
        $lines = [];
        foreach ($routes as $id => $set) {
            $ids = array_keys($set);
            usort($ids, static fn (string $a, string $b) => ($order[$a] ?? \PHP_INT_MAX) <=> ($order[$b] ?? \PHP_INT_MAX));
            $lines[$id] = array_values(array_filter(array_map(static fn (string $route) => $all[$route] ?? null, $ids)));
        }

        return $lines;
    }

    /** @return array<string, Line> every line by its identifier, in the feed's order (route_sort_order, then the short names' natural order) */
    private function allLines(): array
    {
        if (null !== $this->lines) {
            return $this->lines;
        }
        $operators = [];
        foreach ($this->operators() as $operator) {
            $operators[$operator->id] = $operator;
        }
        $lines = [];
        foreach ($this->store->rows('SELECT * FROM gtfs_route WHERE feed = ?', [$this->feed]) as $row) {
            $short = self::text($row['short_name'] ?? null);
            $long = self::text($row['long_name'] ?? null);
            $lines[] = [self::text($row['sort_order'] ?? null), new Line(
                Cast::string($row['route_id']), $short ?? $long ?? Cast::string($row['route_id']), Mode::fromGtfs(Cast::int($row['type'])), $this->name,
                null === $short ? null : $long, self::text($row['color'] ?? null), self::text($row['text_color'] ?? null),
                $operators[Cast::string($row['agency_id'] ?? '_')] ?? (1 === \count($operators) ? reset($operators) : null), self::text($row['url'] ?? null),
                null === ($row['sort_order'] ?? null) ? null : Cast::int($row['sort_order']),
            )];
        }
        usort($lines, static fn (array $a, array $b) => [null === $a[0] ? 1 : 0, (int) $a[0], $a[1]->mode->isRail() ? 0 : 1] <=> [null === $b[0] ? 1 : 0, (int) $b[0], $b[1]->mode->isRail() ? 0 : 1] ?: strnatcasecmp($a[1]->name, $b[1]->name));

        $this->lines = [];
        foreach ($lines as [, $line]) {
            $this->lines[$line->id] = $line;
        }

        return $this->lines;
    }

    /** @param array<string, mixed> $row */
    private function stopOf(array $row): Stop
    {
        $lat = $row['lat'] ?? null;
        $lon = $row['lon'] ?? null;

        return new Stop(
            Cast::string($row['stop_id']), Cast::string($row['name']), $this->name,
            is_numeric($lat) && is_numeric($lon) ? new Coordinates((float) $lat, (float) $lon) : null,
            StopKind::fromGtfs(Cast::int($row['location_type'] ?? 0)), self::text($row['code'] ?? null), self::text($row['parent'] ?? null),
            self::text($row['platform'] ?? null), self::text($row['timezone'] ?? null),
            match (Cast::int($row['wheelchair'] ?? 0)) { 1 => true, 2 => false, default => null },
        );
    }

    /** @param array<string, mixed> $row */
    private function operatorOf(array $row): Operator
    {
        return new Operator(Cast::string($row['agency_id']), Cast::string($row['name'] ?? $row['agency_id']), $this->name, self::text($row['url'] ?? null), self::text($row['timezone'] ?? null), self::text($row['phone'] ?? null));
    }

    /** @return array<string, mixed> */
    private function feedRow(): array
    {
        if (null === $this->feedRow) {
            $this->feedRow = $this->store->ready() ? ($this->store->row('SELECT * FROM gtfs_feed WHERE feed = ?', [$this->feed]) ?? []) : [];
        }

        return $this->feedRow;
    }

    private static function text(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}

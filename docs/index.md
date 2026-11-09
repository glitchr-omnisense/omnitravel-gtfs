---
title: GTFS
order: 20
---

# omnitravel/gtfs

```sh
composer require omnisense/omnitravel omnitravel/gtfs
```

Requirements: PHP 8.2, PDO with `pdo_sqlite` (a store in a file) or `pdo_mysql` (MySQL 8 or
MariaDB 10.5); `symfony/http-client` for the real-time feeds. `ext-zip` is used when present.

## Import a feed

A feed is imported once, then again when the network publishes a new one (weekly is common; the
feed's `feed_end_date` says when it stops covering the days):

```php
use Omnitravel\Gtfs\Feed;
use Omnitravel\Gtfs\Importer;
use Omnitravel\Gtfs\Store;

$store = Store::connect('sqlite:/var/gtfs/gtfs.sqlite');      // or 'mysql:host=db;dbname=app', user, password
$report = (new Importer($store))->import(new Feed('/tmp/irigo.zip'), 'angers', ['title' => 'Irigo'],
    static fn (string $file, int $rows) => print("$file: $rows\n"));
```

- `Feed` reads a zip archive (the .txt files may sit in a folder inside it) or a folder of .txt
  files, row by row: a file is never held in memory. Column names are trimmed and the byte order
  mark dropped (Dijon's feed writes `" pickup_type"`).
- The import runs in **one transaction**: the feed's earlier rows go, the new ones come; readers
  keep seeing the previous import until it is committed (SQLite runs in WAL mode). Several feeds
  share a store, each under its name (`[a-z0-9._-]`, 64 characters at most).
- Read: `agency.txt`, `stops.txt`, `routes.txt`, `trips.txt` (with each trip's `shape_id`),
  `shapes.txt` (the points of the trips' shapes only), `stop_times.txt`, `calendar.txt`,
  `calendar_dates.txt`, `feed_info.txt`. Not read: `frequencies.txt`, `transfers.txt`,
  `pathways.txt`, fares, translations. A store made before shapes were read gets its
  `gtfs_trip.shape_id` column and its `gtfs_shape` table from `createSchema()`.
- **A part of a feed** (`FeedFilter`, the importer's fifth argument): routes chosen by agency,
  route type, identifier or short name (each list given narrows the choice; `excludeRoutes` leaves
  out by identifier or short name), and only what they need - their trips, those trips' stop
  times, the stops they call at with their stations, the services they run on, their shapes. The
  routes, trips and stop times are read once before the transaction to choose. A regional feed is
  then imported for a few lines:

  ```php
  // RATP's metro and trams in Île-de-France Mobilités' feed (8.7 million stop times, 1.2 kept)
  $filter = new FeedFilter(agencies: ['IDFM:Operator_100', 'IDFM:Operator_1047'], routeTypes: [0, 1], excludeRoutes: ['ORLYVAL']);
  (new Importer($store))->import(new Feed('/tmp/IDFM-gtfs.zip'), 'ratp', filter: $filter);
  // FeedFilter::fromArray(['agencies' => '...', 'route_types' => [0, 1], 'exclude' => 'ORLYVAL']) from a configuration
  ```
- Times are kept as seconds of the service day (`25:10:05` is 90605), dates as `YYYYMMDD`
  integers, calendars' weekdays as a bit mask.
- Derived at import, for what the provider reads fast: each trip's first and last call (a
  departure is never a trip's last call, an arrival never its first), each route's stops in order
  per direction (from its trip with the most stops), the routes calling at each stop.

Measured on 2026-11-09 in the family's harness (PHP 8.4, SQLite, a Mac's disk): Angers' Irigo
(1,771 stops, 104 routes, 9,423 trips, 323,941 stop times) in 6.8 s; Dijon's Divia (1,127 stops,
59 routes, 30,189 trips, 710,923 stop times) in 30.5 s; both in a 150 MB SQLite file. A stop's
next eight departures: 28 ms.

The schema is the store's own (tables `gtfs_*`, made by `createSchema()`, which the importer
calls): an application's migrations leave it alone.

## Options

| Option | Default | |
|---|---|---|
| `feed` | (required) | the feed's name in the store |
| `dsn` | (required) | `sqlite:/path/gtfs.sqlite`, `mysql:host=...;dbname=...`; feeds of one DSN share one connection (`GtfsFactory::store()`) |
| `user`, `password` | none | the database's |
| `trip_updates_url` | none | its GTFS-Realtime trip updates: delays, cancellations, skipped stops |
| `vehicle_positions_url` | none | its GTFS-Realtime vehicle positions |
| `alerts_url` | none | its GTFS-Realtime service alerts (`alerts()`; a combined feed's alerts are read from `trip_updates_url` too) |
| `siri_stop_monitoring_url`, `siri_general_message_url` | none | a network's SIRI Lite (SIRI 2.0 as JSON): StopMonitoring laid over the timetable, GeneralMessage as alerts |
| `siri_headers` | `[]` | sent with the SIRI requests: the key, given by reference (PRIM: `apikey`; CTS: `Authorization: Basic` with the token as user) |
| `siri_stop_ref`, `siri_stop_area_ref`, `siri_line_ref` | `{id}`, none, `{id}` | how a GTFS identifier is written in SIRI, `{id}` standing for it without `siri_prefix` (IDFM: `STIF:StopPoint:Q:{id}:`, `STIF:StopArea:SP:{id}:`, `STIF:Line::{id}:`) |
| `siri_prefix` | `""` | the GTFS identifiers' prefix `{id}` leaves out (IDFM: `IDFM:`) |
| `realtime_headers` | `[]` | sent with the real-time requests (a key, where the network asks one) |
| `user_agent` | `omnitravel-gtfs/1.0` | the real-time requests' User-Agent |
| `realtime_ttl` | 30 | seconds a real-time read is kept |
| `realtime_cache` | none | a folder where it is kept between requests (one file per feed and URL, written atomically) |
| `timezone` | the agency's | the time zone the feed's times are written in |
| `title`, `attribution`, `licence` | the feed's publisher, none, none | what `getTitle()` and `Capabilities` say |
| `countries` | `[]` | ISO codes, a list or "fr,be" |

## Capabilities

`timetables` (with `arrivals`, `platforms`), `network` (with `nearby`, `stopSearch`, `lines`);
`realtime` when `trip_updates_url` is set; `tracking` when `vehicle_positions_url` is set (otherwise
`vehicles()` throws `NotSupportedException`); `modes` from the routes' `route_type` (basic and
extended); `area` the box of the feed's stops; `keyless`.

## How it reads

- **Stops near a point**: the stops and stations of the square around the circle (an index on
  latitude), then `Stop::nearest()`; a station's platforms are folded into it, never listed. Each
  stop carries its lines (a station: its platforms').
- **Search**: every word of the text, lower case and without accents, in the name (`Text`);
  exact names first, then names that start with the text, then those holding it as a word; the
  nearest first among equals when a point is given.
- **Lines**: `route_sort_order` first, then rail before road, then the short names' natural order
  (2 before 10). The colour is normalised to `#RRGGBB`; without a `route_text_color`, black or white
  by WCAG contrast.
- **Departures**: the services running on each day (calendar weekdays and dates, then
  calendar_dates' additions and removals) from the day before - a trip at 25:00 of yesterday's
  service leaves today at 01:00 - to two days after, until the query's limit is reached. Times are
  read from "noon minus twelve hours" of the service day in the feed's time zone, as GTFS defines
  them, so a day of clock change is right. Calls where `pickup_type` (departures) or
  `drop_off_type` (arrivals) is 1 are left out. The headsign is the stop time's, then the trip's,
  then the route's long name; the platform is the stop's `platform_code`.
- **Real time**: one read of the trip updates per `realtime_ttl` for the whole timetable. For each
  call: a cancelled (or deleted) trip cancels it; a `SKIPPED` stop cancels that call; otherwise the
  call's own update, or the last one before it on the trip that gives a time (GTFS-Realtime's
  propagation), gives the delay. A call is found by its stop when the update names its stops, by its
  sequence otherwise: networks number their real-time calls their own way (Angers' Irigo counts from 1
  where its static feed counts from 0, which read by sequence put every delay one stop early) - a `time` beats a `delay`, the departure's beats the arrival's; a
  trip's own `delay` is used last. An update naming a `start_date` applies to that service day
  only. Calls scheduled up to 30 minutes before the moment asked are read too, so a late vehicle is
  still listed. When the feed cannot be read, the timetable is answered without it and
  `realtimeError()` says why.
- **SIRI Lite** (`Siri\SiriLite`), for networks that publish SIRI rather than GTFS-Realtime
  (Île-de-France Mobilités' PRIM, Strasbourg's CTS): a stop is asked by `siri_stop_ref`, a station
  by `siri_stop_area_ref` (by each of its platforms without one), and its `MonitoredStopVisit`s are
  read back into the feed's identifiers (`LineRef` and `MonitoringRef` through the same templates).
  For each line and platform SIRI announces, its visits replace that line's scheduled calls at that
  platform up to the last visit announced (a minute's margin), and the timetable goes on after it;
  another platform or another line keeps its timetable. A visit's time is SIRI's expected one; its
  scheduled time is the aimed one when SIRI gives it (PRIM's metro gives none: no delay is made
  up); `DepartureStatus: cancelled` cancels it. A `GeneralMessage` is a `ServiceAlert` of its lines
  and stop points (`SHORT_MESSAGE` its header, the others its description, `ValidUntilTime` its end,
  `InfoChannelRef: Perturbation` a warning), asked per line and kept at least two minutes. When
  SIRI cannot be read (no key: 401), the timetable stays and `realtimeError()` says why.
- **Service alerts** (`alerts($lines, $stops, $at)`, a `ServiceAlert` each): read from `alerts_url`
  and from the alerts a combined feed carries beside its trip updates, once per `realtime_ttl`. An
  alert is kept when it is in force at the moment (one of its `active_period`s, or always when it
  has none) and concerns the board: an entity naming one of the lines, one of the stops (a station
  and its platforms are one), both when it names both, a trip of one of the lines, or the whole
  network (an agency, a route type). Its texts by language (`headerText()`, `descriptionText()`,
  `urlFor()`: the language asked, then French, English, the first given); `cause`, `effect`,
  `severity` as GTFS-Realtime's names in kebab case (`construction`, `no-service`, `warning`).
  The body is read whatever its `Content-Type` (Angers' alerts come as `application/json`).
- **Vehicles**: positions at 0,0 (no fix) are left out; the line comes from the trip descriptor's
  route, or from the trip in the feed.

## Errors

`InvalidArgumentException` for a path that is not a feed (`Not a GTFS feed: "x" has no
stops.txt.`) or a bad feed name; `InvalidConfigException` for a missing option or a store that
cannot be opened; `ProviderException` for a real-time body that is not GTFS-Realtime (caught by
the timetable: see `realtimeError()`); `PDOException` from the store as PDO throws it.

## Verified, and not

- Verified: the fixture feed (a station of two platforms, a tram and a bus line, a night trip past
  midnight, a holiday, a service added by date) imported from a folder and from a deflated zip
  read with zlib alone; real-time feeds written with `WireWriter` (a delay propagated, a
  cancellation, a skipped stop, a time rather than a delay, a deleted entity, alerts in two
  languages with periods, a station, a trip and an agency); three feeds of Angers' Irigo network
  (ODbL) recorded on 2026-11-09 at 12:54 UTC (trip updates, vehicle positions) and 2026-11-10
  (alerts, from notify.ratpdev.com) and read whole; SIRI Lite answers written for the fixture feed
  (a delay, a cancellation without an aimed time, a platform left alone, a 401) and Île-de-France
  Mobilités' PRIM StopMonitoring for Châtelet and GeneralMessage for metro line 1 recorded on
  2026-11-09 at 23:18 UTC, the key left out; Angers' and Dijon's
  static feeds imported and read in the harness (above).
- Not verified: a store in MySQL (the SQL is written for both; the site and the tests run on
  SQLite), `frequencies.txt` networks (their trips are read as their first run only), feeds over
  4 GB, Zip64 archives beyond the extra field writers put on small files.

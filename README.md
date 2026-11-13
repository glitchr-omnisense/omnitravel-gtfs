# omnitravel/gtfs

[omnisense/omnitravel](https://github.com/glitchr-omnisense/omnitravel)'s GTFS, under any PHP host:
any network that publishes a static [GTFS](https://gtfs.org/documentation/schedule/reference/) feed,
imported into a store of its own (an SQLite file or MySQL, through PDO), and its
[GTFS-Realtime](https://gtfs.org/documentation/realtime/reference/) feeds laid over it.

- **the network** (`NetworkInterface`): the stops near a point, nearest first, with the lines calling
  there; stops by name, accents ignored; the lines in the feed's order with their colours; a line's
  stops in order, per direction; the operators;
- **a stop's timetable** (`TimetablesInterface`): its next departures and arrivals - a station's from
  all its platforms - from the feed's calendars, past midnight included (a trip at 25:10), with the
  delays, cancellations and skipped stops of the network's trip updates when it publishes them;
- **vehicles** (`TrackingInterface`): the positions of its vehicle feed, each with its line;
- **service alerts** (`alerts()`): the GTFS-Realtime alerts in force that concern a board's lines
  and stops, their texts by language;
- **estimated vehicles** (`estimatedVehicles()`): where a line's vehicles are from their times -
  real time where it is known - along their shapes, for networks that send no positions;
- **SIRI Lite**, for networks that publish SIRI rather than GTFS-Realtime (Île-de-France Mobilités'
  PRIM, Strasbourg's CTS): StopMonitoring laid over the timetable, GeneralMessage as alerts.

## Install and a first call

```sh
composer require omnitravel/gtfs
```

No account and no network: a one-line feed written by the script, imported into SQLite in memory,
then the stops near a point and a stop's next departures. A real network's feed is the zip it
publishes, imported into an SQLite file or MySQL, again whenever the network publishes a new one.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitravel\Gtfs\{Feed, GtfsFactory, Importer};
use Omnitravel\Model\{Coordinates, DepartureQuery};

$dir = sys_get_temp_dir().'/gtfs-first-call';
@mkdir($dir);
file_put_contents("$dir/agency.txt", "agency_id,agency_name,agency_url,agency_timezone\nX,Example Transit,https://transit.example.org,Europe/Paris\n");
file_put_contents("$dir/stops.txt", "stop_id,stop_name,stop_lat,stop_lon\nGARE,Gare,47.4645,-0.5567\nRALL,Ralliement,47.4712,-0.5518\n");
file_put_contents("$dir/routes.txt", "route_id,agency_id,route_short_name,route_type,route_color\nA,X,A,0,379E32\n");
file_put_contents("$dir/trips.txt", "route_id,service_id,trip_id,trip_headsign\nA,DAILY,A-1,Ralliement\nA,DAILY,A-2,Ralliement\n");
file_put_contents("$dir/stop_times.txt", "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nA-1,08:00:00,08:00:00,GARE,1\nA-1,08:06:00,08:06:00,RALL,2\nA-2,08:15:00,08:15:00,GARE,1\nA-2,08:21:00,08:21:00,RALL,2\n");
file_put_contents("$dir/calendar.txt", "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nDAILY,1,1,1,1,1,1,1,20260101,20361231\n");

$factory = new GtfsFactory();                               // an HTTP client is needed for real-time feeds only
(new Importer($factory->store('sqlite::memory:')))->import(new Feed($dir), 'example');
$network = $factory->create(['feed' => 'example', 'dsn' => 'sqlite::memory:']);

$stop = $network->nearby(new Coordinates(47.4646, -0.5566), 300)[0];
echo $stop->name, "\n";                                     // Gare
$from = new DateTimeImmutable('tomorrow 07:30', new DateTimeZone('Europe/Paris'));
foreach ($network->departures(new DepartureQuery($stop->id, $from, limit: 2)) as $departure) {
    echo $departure->time()->format('H:i'), ' ', $departure->line?->name, ' to ', $departure->headsign, "\n";   // 08:00 A to Ralliement, 08:15 ...
}
```

With a network's real feed and its GTFS-Realtime trip updates:

```php
use Omnitravel\Gtfs\{Feed, GtfsFactory, Importer};
use Omnitravel\Model\{Coordinates, DepartureQuery};

$factory = new GtfsFactory($httpClient);                     // the client reads the real-time feeds only
(new Importer($factory->store('sqlite:/var/gtfs/gtfs.sqlite')))->import(new Feed('/tmp/irigo.zip'), 'angers');

$angers = $factory->create([
    'feed' => 'angers',
    'dsn' => 'sqlite:/var/gtfs/gtfs.sqlite',
    'trip_updates_url' => 'https://ara-api.enroute.mobi/irigo/gtfs/trip-updates',
    'attribution' => 'Irigo - Angers Loire Métropole, ODbL',
]);
$stop = $angers->nearby(new Coordinates(47.4712, -0.5518), 300)[0];
foreach ($angers->departures(new DepartureQuery($stop->id, limit: 5)) as $departure) {
    printf("%s %s %s%s\n", $departure->time()->format('H:i'), $departure->line?->name, $departure->headsign, $departure->isLate() ? ' (late)' : '');
}
```

Nothing but PHP's PDO (with `pdo_sqlite` or `pdo_mysql`) and zlib: an archive is read with
`ZipArchive` where PHP has the zip extension, by the package's own `Zip` otherwise, and GTFS-Realtime's
Protocol Buffers by its own `WireReader` - no generated classes, no protobuf extension.

The manual: [docs/index.md](docs/index.md). MIT.

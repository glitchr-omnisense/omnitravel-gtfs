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
  and stops, their texts by language.

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

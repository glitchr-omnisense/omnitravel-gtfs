<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Exception\InvalidConfigException;

/**
 * Where imported feeds live: a few tables (prefix gtfs_) in an SQLite file
 * or a MySQL/MariaDB database, reached through PDO. Several feeds share a
 * store, each row under its feed's name. Times are kept as seconds from the
 * service day's start ("25:10:00" is 90600), dates as YYYYMMDD integers.
 *
 * The schema is the store's own: createSchema() makes what is missing, and
 * an application's own migrations leave these tables alone.
 */
final class Store
{
    public const TABLES = ['gtfs_feed', 'gtfs_agency', 'gtfs_stop', 'gtfs_route', 'gtfs_trip', 'gtfs_stop_time', 'gtfs_calendar', 'gtfs_calendar_date', 'gtfs_route_stop', 'gtfs_stop_route', 'gtfs_shape'];

    private bool $schemaChecked = false;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if ($this->isSqlite()) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    /** "sqlite:/path/to/gtfs.sqlite" (its folder made when missing), "mysql:host=...;dbname=..." */
    public static function connect(string $dsn, ?string $user = null, ?string $password = null): self
    {
        if (str_starts_with($dsn, 'sqlite:') && !str_starts_with($dsn, 'sqlite::memory:')) {
            $dir = \dirname(substr($dsn, 7));
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new InvalidConfigException(\sprintf('The GTFS store\'s folder "%s" cannot be made.', $dir));
            }
        }
        try {
            return new self(new \PDO($dsn, $user, $password));
        } catch (\PDOException $e) {
            throw new InvalidConfigException(\sprintf('The GTFS store cannot be opened (%s): %s', strtok($dsn, ':'), $e->getMessage()), 0, $e);
        }
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function isSqlite(): bool
    {
        return 'sqlite' === $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function createSchema(): void
    {
        $key = 'VARCHAR(191) NOT NULL';
        $text = 'VARCHAR(255)';
        $int = 'INTEGER';
        $real = 'DOUBLE PRECISION';
        $compact = $this->isSqlite() ? ' WITHOUT ROWID' : '';
        $tables = [
            'gtfs_feed' => "feed $key, title $text, timezone VARCHAR(64), publisher $text, url VARCHAR(500), version $text, lang VARCHAR(16), start_date $int, end_date $int, imported_at $int, south $real, west $real, north $real, east $real, stops $int, trips $int, stop_times $int, PRIMARY KEY (feed)",
            'gtfs_agency' => "feed $key, agency_id $key, name $text, url VARCHAR(500), timezone VARCHAR(64), phone VARCHAR(64), PRIMARY KEY (feed, agency_id)",
            'gtfs_stop' => "feed $key, stop_id $key, code VARCHAR(64), name $text, search $text, lat $real, lon $real, location_type $int, parent VARCHAR(191), platform VARCHAR(64), timezone VARCHAR(64), wheelchair $int, PRIMARY KEY (feed, stop_id)",
            'gtfs_route' => "feed $key, route_id $key, agency_id VARCHAR(191), short_name VARCHAR(64), long_name $text, type $int, color VARCHAR(8), text_color VARCHAR(8), url VARCHAR(500), sort_order $int, PRIMARY KEY (feed, route_id)",
            'gtfs_trip' => "feed $key, trip_id $key, route_id VARCHAR(191), service_id VARCHAR(191), headsign $text, short_name VARCHAR(64), direction_id $int, shape_id VARCHAR(191), PRIMARY KEY (feed, trip_id)",
            'gtfs_stop_time' => "feed $key, trip_id $key, seq $int NOT NULL, stop_id VARCHAR(191), arrival $int, departure $int, headsign $text, pickup $int, drop_off $int, is_first $int, is_last $int, PRIMARY KEY (feed, trip_id, seq)",
            'gtfs_calendar' => "feed $key, service_id $key, days $int, start_date $int, end_date $int, PRIMARY KEY (feed, service_id)",
            'gtfs_calendar_date' => "feed $key, service_id $key, date $int NOT NULL, type $int, PRIMARY KEY (feed, service_id, date)",
            'gtfs_route_stop' => "feed $key, route_id $key, direction VARCHAR(16) NOT NULL, seq $int NOT NULL, stop_id VARCHAR(191), PRIMARY KEY (feed, route_id, direction, seq)",
            'gtfs_stop_route' => "feed $key, stop_id $key, route_id $key, PRIMARY KEY (feed, stop_id, route_id)",
            'gtfs_shape' => "feed $key, shape_id $key, seq $int NOT NULL, lat $real, lon $real, dist $real, PRIMARY KEY (feed, shape_id, seq)",
        ];
        foreach ($tables as $table => $columns) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS $table ($columns)".(\in_array($table, ['gtfs_stop_time', 'gtfs_shape'], true) ? $compact : ''));
        }
        // A store made before trips had their shape gets the column.
        if (!\in_array('shape_id', $this->columns('gtfs_trip'), true)) {
            $this->pdo->exec('ALTER TABLE gtfs_trip ADD COLUMN shape_id VARCHAR(191)');
        }
        $indexes = [
            'gtfs_stop_place' => 'gtfs_stop (feed, lat, lon)',
            'gtfs_stop_parent' => 'gtfs_stop (feed, parent)',
            'gtfs_trip_route' => 'gtfs_trip (feed, route_id)',
            'gtfs_stop_time_departure' => 'gtfs_stop_time (feed, stop_id, departure)',
            'gtfs_stop_time_arrival' => 'gtfs_stop_time (feed, stop_id, arrival)',
            'gtfs_calendar_date_day' => 'gtfs_calendar_date (feed, date)',
        ];
        $existing = $this->indexes();
        foreach ($indexes as $name => $on) {
            if (!\in_array($name, $existing, true)) {
                $this->pdo->exec("CREATE INDEX $name ON $on");
            }
        }
        $this->schemaChecked = true;
    }

    /** Whether the schema is there (checked once). */
    public function ready(): bool
    {
        if ($this->schemaChecked) {
            return true;
        }
        try {
            $this->pdo->query('SELECT 1 FROM gtfs_feed WHERE 1 = 0');

            return $this->schemaChecked = true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Every row of a feed taken away. */
    public function deleteFeed(string $feed): void
    {
        foreach (self::TABLES as $table) {
            $this->pdo->prepare("DELETE FROM $table WHERE feed = ?")->execute([$feed]);
        }
    }

    /**
     * Rows written by the hundred (each row in the columns' order).
     *
     * @param list<string>                          $columns
     * @param iterable<list<string|int|float|null>> $rows
     */
    public function insert(string $table, array $columns, iterable $rows, int $batch = 400): int
    {
        $count = 0;
        $buffer = [];
        foreach ($rows as $row) {
            $buffer[] = $row;
            if (\count($buffer) >= $batch) {
                $count += $this->write($table, $columns, $buffer);
                $buffer = [];
            }
        }

        return $count + $this->write($table, $columns, $buffer);
    }

    /**
     * @param list<string|int|float|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (\is_array($row)) {
                $rows[] = array_combine(array_map('strval', array_keys($row)), array_values($row));
            }
        }

        return $rows;
    }

    /**
     * @param list<string|int|float|null> $parameters
     *
     * @return ?array<string, mixed>
     */
    public function row(string $sql, array $parameters = []): ?array
    {
        return $this->rows($sql, $parameters)[0] ?? null;
    }

    /**
     * "?, ?, ?" for a list of values.
     *
     * @param array<mixed> $values
     */
    public static function marks(array $values): string
    {
        return implode(', ', array_fill(0, max(1, \count($values)), '?'));
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<string>                      $columns
     * @param list<list<string|int|float|null>> $rows
     */
    private function write(string $table, array $columns, array $rows): int
    {
        if ([] === $rows) {
            return 0;
        }
        $one = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        $this->pdo->prepare('INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES '.implode(', ', array_fill(0, \count($rows), $one)))->execute(array_merge(...$rows));

        return \count($rows);
    }

    /** @return list<string> a table's columns */
    private function columns(string $table): array
    {
        $rows = $this->isSqlite()
            ? $this->rows("PRAGMA table_info($table)")
            : $this->rows('SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]);

        return array_map(static fn (array $row) => Cast::string($row['name']), $rows);
    }

    /** @return list<string> */
    private function indexes(): array
    {
        $sql = $this->isSqlite()
            ? "SELECT name FROM sqlite_master WHERE type = 'index' AND name LIKE 'gtfs_%'"
            : "SELECT DISTINCT index_name AS name FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name LIKE 'gtfs_%'";

        return array_map(static fn (array $row) => Cast::string($row['name']), $this->rows($sql));
    }
}

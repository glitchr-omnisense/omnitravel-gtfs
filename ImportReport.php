<?php

namespace Omnitravel\Gtfs;

/** What an import wrote: rows per file, and how long it took. */
final readonly class ImportReport
{
    /** @param array<string, int> $rows by file ("stops.txt"), and the derived tables ("route stops", "stop routes") */
    public function __construct(public string $feed, public array $rows, public float $seconds)
    {
    }

    public function count(string $file): int
    {
        return $this->rows[$file] ?? 0;
    }
}

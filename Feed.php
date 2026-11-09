<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Exception\InvalidArgumentException;

/**
 * A static GTFS feed on disk - its zip archive, or a directory of its .txt
 * files - read file by file, row by row, without holding a file in memory.
 * A zip is opened with ZipArchive where PHP has the zip extension, with
 * Zip otherwise (the archive's directory read by hand, its entries inflated
 * with zlib).
 */
final class Feed
{
    private ?string $root = null;

    /** @var array<string, string> each .txt file's path inside the archive, by its name */
    private array $entries = [];

    private ?\ZipArchive $zip = null;

    private ?Zip $reader = null;

    public function __construct(private readonly string $path)
    {
        if (is_dir($path)) {
            $this->root = rtrim($path, '/');
            foreach (glob($this->root.'/*.txt') ?: [] as $file) {
                $this->entries[basename($file)] = $file;
            }
        } elseif (is_file($path)) {
            $this->openZip();
        } else {
            throw new InvalidArgumentException(\sprintf('No GTFS feed at "%s".', $path));
        }
        foreach (['stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt'] as $required) {
            if (!isset($this->entries[$required])) {
                throw new InvalidArgumentException(\sprintf('Not a GTFS feed: "%s" has no %s.', basename($path), $required));
            }
        }
    }

    public function has(string $file): bool
    {
        return isset($this->entries[$file]);
    }

    /** @return list<string> the .txt files it holds */
    public function files(): array
    {
        return array_keys($this->entries);
    }

    /**
     * The rows of a file, each by its column names (trimmed, the byte order
     * mark dropped); none when the file is absent.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function rows(string $file): \Generator
    {
        if (!isset($this->entries[$file])) {
            return;
        }
        $handle = $this->open($file);
        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!\is_array($header)) {
                return;
            }
            $header = array_map(static fn ($name) => trim(str_replace("\u{FEFF}", '', (string) $name)), $header);
            $count = \count($header);
            while (\is_array($row = fgetcsv($handle, null, ',', '"', ''))) {
                if ([null] === $row) {
                    continue;
                }
                $row = array_map(static fn ($value) => trim((string) $value), $row);
                $row = \count($row) >= $count ? \array_slice($row, 0, $count) : array_pad($row, $count, '');
                yield array_combine($header, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open(string $file)
    {
        $entry = $this->entries[$file];
        $handle = match (true) {
            null !== $this->root => fopen($entry, 'r'),
            null !== $this->zip => $this->zip->getStream($entry),
            null !== $this->reader => $this->reader->open($file),
            default => false,
        };

        return \is_resource($handle) ? $handle : throw new InvalidArgumentException(\sprintf('The feed\'s %s cannot be read.', $file));
    }

    private function openZip(): void
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if (true !== $zip->open($this->path, \ZipArchive::RDONLY)) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a zip archive.', basename($this->path)));
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '.txt') && !str_starts_with(basename($name), '.')) {
                    $this->entries[basename($name)] ??= $name;
                }
            }
            $this->zip = $zip;

            return;
        }
        $this->reader = new Zip($this->path);
        foreach ($this->reader->names() as $name) {
            $this->entries[$name] = $name;
        }
    }
}

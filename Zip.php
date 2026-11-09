<?php

namespace Omnitravel\Gtfs;

use Omnitravel\Exception\InvalidArgumentException;

/**
 * A zip archive read with zlib alone, for a PHP built without the zip
 * extension: its central directory, then one entry at a time, stored or
 * deflated, inflated by chunks into a temporary stream (in memory up to
 * 2 MB, on disk beyond). Enough for a GTFS archive: no encryption, and of
 * Zip64 only what writers put on small files too (an entry's sizes and
 * offset in its extra field), not an archive over 4 GB.
 */
final class Zip
{
    /** @var array<string, array{method: int, compressed: int, offset: int, name: string}> by base name */
    private array $entries = [];

    public function __construct(private readonly string $path)
    {
        $handle = fopen($path, 'r');
        if (false === $handle) {
            throw new InvalidArgumentException(\sprintf('"%s" cannot be read.', basename($path)));
        }
        try {
            $stat = fstat($handle);
            $size = false === $stat ? 0 : $stat['size'];
            $tail = max(1, min($size, 65557));
            fseek($handle, max(0, $size - $tail));
            $end = (string) fread($handle, $tail);
            $at = strrpos($end, "PK\x05\x06");
            if (false === $at) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a zip archive.', basename($path)));
            }
            $record = unpack('vdisk/vstart/ventries/vtotal/Vsize/Voffset', substr($end, $at + 4, 16));
            if (!\is_array($record) || !\is_int($record['size'] ?? null) || !\is_int($record['offset'] ?? null)) {
                throw new InvalidArgumentException(\sprintf('"%s" is not a zip archive.', basename($path)));
            }
            fseek($handle, $record['offset']);
            $directory = (string) fread($handle, max(1, $record['size']));
            for ($i = 0; $i + 46 <= \strlen($directory) && "PK\x01\x02" === substr($directory, $i, 4);) {
                $entry = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($directory, $i + 4, 42));
                if (!\is_array($entry)) {
                    break;
                }
                $name = substr($directory, $i + 46, self::int($entry, 'name'));
                [$compressed, $offset] = self::zip64($entry, substr($directory, $i + 46 + self::int($entry, 'name'), self::int($entry, 'extra')));
                if (str_ends_with($name, '.txt') && !str_starts_with(basename($name), '.')) {
                    $this->entries[basename($name)] ??= ['method' => self::int($entry, 'method'), 'compressed' => $compressed, 'offset' => $offset, 'name' => $name];
                }
                $i += 46 + self::int($entry, 'name') + self::int($entry, 'extra') + self::int($entry, 'comment');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> the .txt entries' base names */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /** @return resource an entry's content, from its start */
    public function open(string $name)
    {
        $entry = $this->entries[$name] ?? throw new InvalidArgumentException(\sprintf('No %s in "%s".', $name, basename($this->path)));
        if (!\in_array($entry['method'], [0, 8], true)) {
            throw new InvalidArgumentException(\sprintf('%s is compressed in a way zlib does not read (method %d).', $name, $entry['method']));
        }
        $in = fopen($this->path, 'r');
        $out = fopen('php://temp/maxmemory:2097152', 'w+');
        if (false === $in || false === $out) {
            throw new InvalidArgumentException(\sprintf('%s cannot be read.', $name));
        }
        try {
            fseek($in, $entry['offset']);
            $local = unpack('Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', (string) fread($in, 30));
            if (!\is_array($local) || 0x04034B50 !== ($local['signature'] ?? null)) {
                throw new InvalidArgumentException(\sprintf('%s: the archive is damaged.', $name));
            }
            fseek($in, self::int($local, 'name') + self::int($local, 'extra'), \SEEK_CUR);
            $inflate = 8 === $entry['method'] ? inflate_init(\ZLIB_ENCODING_RAW) : null;
            if (false === $inflate) {
                throw new InvalidArgumentException(\sprintf('%s cannot be inflated.', $name));
            }
            for ($left = $entry['compressed']; $left > 0;) {
                $chunk = (string) fread($in, min($left, 1 << 20));
                if ('' === $chunk) {
                    break;
                }
                $left -= \strlen($chunk);
                $data = null === $inflate ? $chunk : inflate_add($inflate, $chunk, $left > 0 ? \ZLIB_SYNC_FLUSH : \ZLIB_FINISH);
                if (false === $data) {
                    throw new InvalidArgumentException(\sprintf('%s: the archive is damaged.', $name));
                }
                fwrite($out, $data);
            }
        } finally {
            fclose($in);
        }
        rewind($out);

        return $out;
    }

    /**
     * The compressed size and the local header's offset, from the Zip64
     * extra field (0x0001) where the directory says 0xFFFFFFFF: its values
     * come in the order size, compressed size, offset, each present only
     * when the directory's own is 0xFFFFFFFF.
     *
     * @param array<mixed> $entry
     *
     * @return array{0: int, 1: int}
     */
    private static function zip64(array $entry, string $extra): array
    {
        $values = ['size' => self::int($entry, 'size'), 'compressed' => self::int($entry, 'compressed'), 'offset' => self::int($entry, 'offset')];
        for ($at = 0; $at + 4 <= \strlen($extra);) {
            $header = unpack('vid/vlength', substr($extra, $at, 4));
            $id = \is_array($header) ? self::int($header, 'id') : 0;
            $length = \is_array($header) ? self::int($header, 'length') : 0;
            if (0x0001 === $id) {
                $field = substr($extra, $at + 4, $length);
                $read = 0;
                foreach (['size', 'compressed', 'offset'] as $key) {
                    if (0xFFFFFFFF === $values[$key] && $read + 8 <= \strlen($field)) {
                        $number = unpack('P', substr($field, $read, 8));
                        $values[$key] = \is_array($number) ? self::int($number, '1') : 0;
                        $read += 8;
                    }
                }
            }
            $at += 4 + $length;
        }

        return [$values['compressed'], $values['offset']];
    }

    /** @param array<mixed> $values */
    private static function int(array $values, string $key): int
    {
        return \is_int($values[$key] ?? null) ? $values[$key] : 0;
    }
}

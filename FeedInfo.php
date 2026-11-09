<?php

namespace Omnitravel\Gtfs;

/** An imported feed as the store keeps it: its title and publisher, the days it covers, its size, when it was imported. */
final readonly class FeedInfo
{
    public function __construct(
        public string $feed,
        public string $title,
        public ?string $timezone,
        public ?string $publisher,
        public ?string $url,
        public ?string $version,
        public ?\DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public \DateTimeImmutable $imported,
        public int $stops,
        public int $trips,
        public int $stopTimes,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $date = static fn (mixed $value): ?\DateTimeImmutable => is_numeric($value) && 8 === \strlen((string) $value) ? (\DateTimeImmutable::createFromFormat('!Ymd', (string) $value) ?: null) : null;
        $text = static fn (mixed $value): ?string => \is_scalar($value) && '' !== (string) $value ? (string) $value : null;

        return new self(
            Cast::string($row['feed'] ?? ''), Cast::string($row['title'] ?? $row['feed'] ?? ''), $text($row['timezone'] ?? null), $text($row['publisher'] ?? null), $text($row['url'] ?? null), $text($row['version'] ?? null),
            $date($row['start_date'] ?? null), $date($row['end_date'] ?? null), new \DateTimeImmutable('@'.Cast::int($row['imported_at'] ?? 0)),
            Cast::int($row['stops'] ?? 0), Cast::int($row['trips'] ?? 0), Cast::int($row['stop_times'] ?? 0),
        );
    }

    /** Whether the feed's own validity, where it states one, holds this day. */
    public function covers(\DateTimeImmutable $day): bool
    {
        $date = $day->format('Ymd');

        return (null === $this->start || $date >= $this->start->format('Ymd')) && (null === $this->end || $date <= $this->end->format('Ymd'));
    }
}

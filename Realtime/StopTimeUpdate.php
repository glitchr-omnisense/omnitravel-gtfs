<?php

namespace Omnitravel\Gtfs\Realtime;

/** GTFS-Realtime's StopTimeUpdate: a trip's call at one stop, as expected. */
final readonly class StopTimeUpdate
{
    /**
     * @param ?int $arrivalDelay   seconds
     * @param ?int $arrivalTime    a Unix time
     * @param ?int $departureDelay seconds
     * @param ?int $departureTime  a Unix time
     * @param bool $skipped        the vehicle does not stop there (SKIPPED)
     * @param bool $noData         the feed says it knows nothing of this call (NO_DATA)
     */
    public function __construct(
        public ?int $sequence = null,
        public ?string $stopId = null,
        public ?int $arrivalDelay = null,
        public ?int $arrivalTime = null,
        public ?int $departureDelay = null,
        public ?int $departureTime = null,
        public bool $skipped = false,
        public bool $noData = false,
    ) {
    }

    public static function parse(string $message): self
    {
        $values = ['sequence' => null, 'stopId' => null, 'arrival' => [null, null], 'departure' => [null, null], 'relationship' => 0];
        foreach (WireReader::fields($message) as [$number, , $value]) {
            match ($number) {
                1 => $values['sequence'] = (int) $value,
                2 => $values['arrival'] = self::event((string) $value),
                3 => $values['departure'] = self::event((string) $value),
                4 => $values['stopId'] = (string) $value,
                5 => $values['relationship'] = (int) $value,
                default => null,
            };
        }

        return new self($values['sequence'], $values['stopId'], $values['arrival'][0], $values['arrival'][1], $values['departure'][0], $values['departure'][1], 1 === $values['relationship'], 2 === $values['relationship']);
    }

    /** Whether it says anything of the time: a delay or a time. */
    public function hasTime(): bool
    {
        return !$this->noData && (null !== $this->arrivalDelay || null !== $this->arrivalTime || null !== $this->departureDelay || null !== $this->departureTime);
    }

    /**
     * The delay of the departure (the arrival's when the departure says
     * nothing), against the scheduled Unix time given; null when it says
     * nothing.
     */
    public function delay(int $scheduled, bool $arrival = false): ?int
    {
        [$first, $second] = $arrival ? [[$this->arrivalDelay, $this->arrivalTime], [$this->departureDelay, $this->departureTime]] : [[$this->departureDelay, $this->departureTime], [$this->arrivalDelay, $this->arrivalTime]];
        foreach ([$first, $second] as [$delay, $time]) {
            if (null !== $time) {
                return $time - $scheduled;
            }
            if (null !== $delay) {
                return $delay;
            }
        }

        return null;
    }

    /** @return array{0: ?int, 1: ?int} StopTimeEvent's delay and time */
    private static function event(string $message): array
    {
        $delay = $time = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            if (1 === $number) {
                $delay = WireReader::int32((int) $value);
            } elseif (2 === $number && 0 !== (int) $value) {
                $time = (int) $value;
            }
        }

        return [$delay, $time];
    }
}

<?php

namespace Omnitravel\Gtfs\Realtime;

/** GTFS-Realtime's TripUpdate: a trip's expected calls, or its cancellation. */
final readonly class TripUpdate
{
    /**
     * @param ?string              $startDate "YYYYMMDD": the service day it runs on, where the feed says it
     * @param list<StopTimeUpdate> $stops     in the order of the trip
     * @param ?int                 $delay     the trip's delay, where the feed gives one for the whole trip
     */
    public function __construct(
        public ?string $tripId,
        public ?string $routeId = null,
        public ?string $startDate = null,
        public ?string $startTime = null,
        public bool $cancelled = false,
        public bool $added = false,
        public array $stops = [],
        public ?string $vehicle = null,
        public ?int $timestamp = null,
        public ?int $delay = null,
    ) {
    }

    public static function parse(string $message): self
    {
        $trip = TripDescriptor::parse('');
        $stops = [];
        $vehicle = $timestamp = $delay = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            match ($number) {
                1 => $trip = TripDescriptor::parse((string) $value),
                2 => $stops[] = StopTimeUpdate::parse((string) $value),
                3 => $vehicle = VehicleDescriptor::parse((string) $value)->id,
                4 => $timestamp = (int) $value,
                5 => $delay = WireReader::int32((int) $value),
                default => null,
            };
        }

        return new self($trip->tripId, $trip->routeId, $trip->startDate, $trip->startTime, $trip->cancelled(), $trip->added(), $stops, $vehicle, $timestamp, $delay);
    }

    /**
     * The update that speaks for the call at this stop and sequence: its own,
     * or the last one before it that gives a time (GTFS-Realtime's
     * propagation of a delay down the trip). A call the feed skips is
     * returned as such.
     *
     * The stop's identifier is trusted first: networks number their
     * real-time calls in their own way (Angers' Irigo counts from 1 where its
     * static feed counts from 0), so a call is found by its stop when the
     * update names it, by its sequence otherwise.
     */
    public function at(?int $sequence, string $stopId): ?StopTimeUpdate
    {
        $named = array_values(array_filter($this->stops, static fn (StopTimeUpdate $update) => null !== $update->stopId));
        if ([] !== $named && \count($named) === \count($this->stops)) {
            $before = null;
            foreach ($this->stops as $update) {
                if ($stopId === $update->stopId) {
                    return $update->hasTime() || $update->skipped ? $update : $before;
                }
                $before = $update->hasTime() ? $update : ($update->noData ? null : $before);
            }

            // The stop is not among the updates: earlier on the trip than the first, or after the last.
            return null !== $sequence && null !== $this->stops[0]->sequence && $sequence > $this->stops[0]->sequence ? $before : null;
        }
        $before = null;
        foreach ($this->stops as $update) {
            $same = null !== $update->sequence && null !== $sequence ? $update->sequence === $sequence : $update->stopId === $stopId;
            if ($same) {
                return $update->hasTime() || $update->skipped ? $update : $before;
            }
            if (null !== $update->sequence && null !== $sequence && $update->sequence > $sequence) {
                break;
            }
            if ($update->hasTime()) {
                $before = $update;
            } elseif ($update->noData) {
                $before = null;
            }
        }

        return null !== $before && null !== $sequence ? $before : null;
    }
}

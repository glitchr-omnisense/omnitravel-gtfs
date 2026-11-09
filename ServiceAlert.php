<?php

namespace Omnitravel\Gtfs;

/**
 * A service alert: what the network tells its travellers of a disruption,
 * works, a stop moved, a strike - from GTFS-Realtime's Alerts, or from a
 * SIRI GeneralMessage (Omnitravel\Gtfs\Siri\SiriLite). Its texts by
 * language, when it is in force, and what it concerns: lines, stops,
 * trips, the whole agency.
 */
final readonly class ServiceAlert
{
    /** GTFS-Realtime's Cause, by its number. */
    public const CAUSES = [1 => 'unknown', 2 => 'other', 3 => 'technical-problem', 4 => 'strike', 5 => 'demonstration', 6 => 'accident', 7 => 'holiday', 8 => 'weather', 9 => 'maintenance', 10 => 'construction', 11 => 'police-activity', 12 => 'medical-emergency'];

    /** GTFS-Realtime's Effect, by its number. */
    public const EFFECTS = [1 => 'no-service', 2 => 'reduced-service', 3 => 'significant-delays', 4 => 'detour', 5 => 'additional-service', 6 => 'modified-service', 7 => 'other', 8 => 'unknown', 9 => 'stop-moved', 10 => 'no-effect', 11 => 'accessibility-issue'];

    /** GTFS-Realtime's SeverityLevel, by its number. */
    public const SEVERITIES = [1 => 'unknown', 2 => 'info', 3 => 'warning', 4 => 'severe'];

    /**
     * @param array<string, string>                                                                              $header      the short text by language ("" when the feed names none)
     * @param array<string, string>                                                                              $description the long text by language
     * @param array<string, string>                                                                              $url         a page about it by language
     * @param list<array{0: ?int, 1: ?int}>                                                                     $periods     when it is in force: [start, end] as timestamps, either open; none: always
     * @param list<array{agency: ?string, route: ?string, routeType: ?int, stop: ?string, trip: ?string, direction: ?int}> $entities    what it concerns
     * @param string                                                                                             $source      "gtfs-rt" or "siri"
     */
    public function __construct(
        public string $id,
        public array $header = [],
        public array $description = [],
        public array $url = [],
        public array $periods = [],
        public array $entities = [],
        public string $cause = 'unknown',
        public string $effect = 'unknown',
        public string $severity = 'unknown',
        public string $source = 'gtfs-rt',
    ) {
    }

    /** In force at a moment: within one of its periods, or always when it gives none. */
    public function activeAt(int $timestamp): bool
    {
        if ([] === $this->periods) {
            return true;
        }
        foreach ($this->periods as [$start, $end]) {
            if ((null === $start || $start <= $timestamp) && (null === $end || $timestamp <= $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether it concerns a board: one of the lines, one of the stops, the
     * pair of both when an entity names both, or the whole network when an
     * entity names only an agency or a route type. An alert of another line
     * at the same stop concerns the stop only when it names no line.
     *
     * @param list<string> $lines GTFS route identifiers
     * @param list<string> $stops GTFS stop identifiers (a station and its platforms)
     */
    public function concerns(array $lines = [], array $stops = []): bool
    {
        foreach ($this->entities as $entity) {
            $route = $entity['route'];
            $stop = $entity['stop'];
            if (null === $route && null === $stop && null === $entity['trip']) {
                if (null !== $entity['agency'] || null !== $entity['routeType']) {
                    return true;
                }
                continue;
            }
            $routeOk = null === $route ? null : \in_array($route, $lines, true);
            $stopOk = null === $stop ? null : \in_array($stop, $stops, true);
            if (false !== $routeOk && false !== $stopOk && (true === $routeOk || true === $stopOk)) {
                return true;
            }
        }

        return false;
    }

    /** The short text in a language: that one, else French, English, the first given. */
    public function headerText(?string $language = null): string
    {
        return self::pick($this->header, $language) ?? self::pick($this->description, $language) ?? '';
    }

    /** The long text in a language; null when it says nothing more than its header. */
    public function descriptionText(?string $language = null): ?string
    {
        $text = self::pick($this->description, $language);

        return null === $text || $text === $this->headerText($language) ? null : $text;
    }

    public function urlFor(?string $language = null): ?string
    {
        return self::pick($this->url, $language);
    }

    /** @return list<string> the routes it names */
    public function routes(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $entity) => $entity['route'], $this->entities), static fn ($route) => null !== $route)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'header' => $this->header, 'description' => $this->description, 'url' => $this->url, 'periods' => $this->periods, 'entities' => $this->entities, 'cause' => $this->cause, 'effect' => $this->effect, 'severity' => $this->severity, 'source' => $this->source];
    }

    /** @param array<string, string> $texts */
    private static function pick(array $texts, ?string $language): ?string
    {
        if ([] === $texts) {
            return null;
        }
        $language = null === $language ? null : strtolower(substr($language, 0, 2));
        foreach ([$language, 'fr', 'en', ''] as $wanted) {
            foreach ($texts as $lang => $text) {
                if (null !== $wanted && strtolower(substr((string) $lang, 0, 2)) === $wanted && '' !== trim($text)) {
                    return trim($text);
                }
            }
        }
        foreach ($texts as $text) {
            if ('' !== trim($text)) {
                return trim($text);
            }
        }

        return null;
    }
}

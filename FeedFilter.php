<?php

namespace Omnitravel\Gtfs;

/**
 * The part of a feed to import: its routes chosen by agency, route type,
 * identifier or short name, and only what they need - their trips, the stop
 * times of those trips, the stops they call at with their stations, the
 * services they run on, their shapes. A regional feed (Île-de-France
 * Mobilités': 8.7 million stop times) is then imported for its metro and
 * trams alone.
 *
 * Every list given narrows the choice (an empty one chooses all); a route
 * excluded is left out whatever the others say.
 */
final readonly class FeedFilter
{
    /**
     * @param list<string> $agencies      agency_id values
     * @param list<int>    $routeTypes    route_type values (0 tram, 1 metro, 3 bus; extended ones as written)
     * @param list<string> $routes        route_id values
     * @param list<string> $shortNames    route_short_name values
     * @param list<string> $excludeRoutes route_id or route_short_name values left out
     */
    public function __construct(
        public array $agencies = [],
        public array $routeTypes = [],
        public array $routes = [],
        public array $shortNames = [],
        public array $excludeRoutes = [],
    ) {
    }

    /**
     * From an application's configuration: agencies, route_types, routes, short_names, exclude
     * (lists, or strings separated by commas).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $list = static function (mixed $value): array {
            $items = \is_string($value) ? explode(',', $value) : (\is_array($value) ? $value : []);

            return array_values(array_filter(array_map(static fn ($item) => \is_scalar($item) ? trim((string) $item) : '', $items), static fn (string $item) => '' !== $item));
        };

        return new self($list($config['agencies'] ?? null), array_map('intval', $list($config['route_types'] ?? null)), $list($config['routes'] ?? null), $list($config['short_names'] ?? null), $list($config['exclude'] ?? null));
    }

    public function isEmpty(): bool
    {
        return [] === $this->agencies && [] === $this->routeTypes && [] === $this->routes && [] === $this->shortNames && [] === $this->excludeRoutes;
    }

    /** @param array<string, string> $route a row of routes.txt */
    public function accepts(array $route): bool
    {
        $id = trim($route['route_id'] ?? '');
        $short = trim($route['route_short_name'] ?? '');
        if (\in_array($id, $this->excludeRoutes, true) || ('' !== $short && \in_array($short, $this->excludeRoutes, true))) {
            return false;
        }

        return ([] === $this->agencies || \in_array(trim($route['agency_id'] ?? ''), $this->agencies, true))
            && ([] === $this->routeTypes || \in_array((int) trim($route['route_type'] ?? '3'), $this->routeTypes, true))
            && ([] === $this->routes || \in_array($id, $this->routes, true))
            && ([] === $this->shortNames || \in_array($short, $this->shortNames, true));
    }
}

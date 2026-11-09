<?php

namespace Omnitravel\Gtfs\Siri;

use Omnitravel\Exception\ProviderException;
use Omnitravel\Gtfs\ServiceAlert;
use Omnitravel\Http\JsonClient;

/**
 * SIRI Lite (SIRI 2.0 as JSON over GET), the real time of networks that
 * publish SIRI rather than GTFS-Realtime - Île-de-France Mobilités' PRIM
 * (RATP's metro and trams), Strasbourg's CTS: a stop's StopMonitoring and a
 * line's GeneralMessage, their references translated to and from the GTFS
 * feed's identifiers by templates.
 *
 * A template holds "{id}", the GTFS identifier without the feed's prefix:
 * with the prefix "IDFM:", the stop "IDFM:22087" is "STIF:StopPoint:Q:22087:"
 * under the template "STIF:StopPoint:Q:{id}:", and "STIF:Line::C01371:" is
 * the route "IDFM:C01371" under "STIF:Line::{id}:".
 *
 * Answers are kept $ttl seconds (messages at least two minutes), in a
 * folder between requests when $cache names one: a key's daily quota is
 * shared by every board.
 */
final class SiriLite
{
    /** @var array<string, array{0: int, 1: array<mixed>}> */
    private array $answers = [];

    public function __construct(
        private readonly JsonClient $http,
        private readonly ?string $stopMonitoringUrl = null,
        private readonly ?string $generalMessageUrl = null,
        private readonly string $stopRef = '{id}',
        private readonly ?string $stopAreaRef = null,
        private readonly string $lineRef = '{id}',
        private readonly string $prefix = '',
        private readonly int $ttl = 30,
        private readonly ?string $cache = null,
        private readonly string $feed = 'siri',
    ) {
    }

    public function monitorsStops(): bool
    {
        return null !== $this->stopMonitoringUrl;
    }

    public function monitorsMessages(): bool
    {
        return null !== $this->generalMessageUrl;
    }

    /**
     * The visits SIRI announces at a stop: a platform by its stop template,
     * a station by its stop area template (or by each of its platforms when
     * there is none).
     *
     * @param list<string> $platforms the station's platforms, when $stop is one
     *
     * @return list<Visit>
     */
    public function visits(string $stop, bool $station = false, array $platforms = []): array
    {
        if (null === $this->stopMonitoringUrl) {
            return [];
        }
        $refs = $station && null !== $this->stopAreaRef ? [self::fill($this->stopAreaRef, $this->local($stop))] : array_map(fn (string $id) => self::fill($this->stopRef, $this->local($id)), $station ? $platforms : [$stop]);
        $visits = [];
        foreach ($refs as $ref) {
            $answer = $this->read($this->stopMonitoringUrl, ['MonitoringRef' => $ref]);
            foreach (self::list(self::path($answer, ['Siri', 'ServiceDelivery', 'StopMonitoringDelivery'])) as $delivery) {
                foreach (self::list(self::path($delivery, ['MonitoredStopVisit'])) as $visit) {
                    $visits[] = $this->visit($visit);
                }
            }
        }

        return $visits;
    }

    /**
     * The messages SIRI's GeneralMessage gives for lines, as service alerts.
     *
     * @param list<string> $lines GTFS route identifiers
     *
     * @return list<ServiceAlert>
     */
    public function messages(array $lines): array
    {
        if (null === $this->generalMessageUrl) {
            return [];
        }
        $alerts = [];
        foreach ($lines as $line) {
            $answer = $this->read($this->generalMessageUrl, ['LineRef' => self::fill($this->lineRef, $this->local($line))], max(120, $this->ttl));
            foreach (self::list(self::path($answer, ['Siri', 'ServiceDelivery', 'GeneralMessageDelivery'])) as $delivery) {
                foreach (self::list(self::path($delivery, ['InfoMessage'])) as $message) {
                    $alert = $this->alert($message);
                    $alerts[$alert->id] = $alert;
                }
            }
        }

        return array_values($alerts);
    }

    /** The GTFS identifier a SIRI reference stands for under a template; null when it does not fit. */
    public function gtfsId(string $reference, string $template): ?string
    {
        [$before, $after] = explode('{id}', $template, 2) + [1 => ''];
        if (!str_starts_with($reference, $before) || ('' !== $after && !str_ends_with($reference, $after)) || \strlen($reference) <= \strlen($before) + \strlen($after)) {
            return null;
        }

        return $this->prefix.substr($reference, \strlen($before), \strlen($reference) - \strlen($before) - \strlen($after));
    }

    /** @param array<mixed> $visit */
    private function visit(array $visit): Visit
    {
        $journey = self::array(self::path($visit, ['MonitoredVehicleJourney']));
        $call = self::array(self::path($journey, ['MonitoredCall']));
        $time = static fn (string $key): ?int => \is_string($call[$key] ?? null) && false !== ($t = strtotime($call[$key])) ? $t : null;
        $status = self::string($call['DepartureStatus'] ?? null) ?? self::string($call['ArrivalStatus'] ?? null);
        $line = self::value(self::path($journey, ['LineRef']));
        $stop = self::value(self::path($visit, ['MonitoringRef'])) ?? self::value(self::path($call, ['StopPointRef']));

        return new Visit(
            null === $line ? null : $this->gtfsId($line, $this->lineRef),
            null === $stop ? null : $this->gtfsId($stop, $this->stopRef),
            self::text($call['DestinationDisplay'] ?? null) ?? self::text($journey['DestinationName'] ?? null) ?? self::text($journey['DirectionName'] ?? null) ?? '',
            $time('ExpectedDepartureTime') ?? $time('ExpectedArrivalTime'),
            $time('AimedDepartureTime') ?? $time('AimedArrivalTime'),
            true === ($call['VehicleAtStop'] ?? false),
            \in_array(strtolower((string) $status), ['cancelled', 'canceled'], true),
            self::text($call['DeparturePlatformName'] ?? null) ?? self::text($call['ArrivalPlatformName'] ?? null),
            self::value(self::path($journey, ['DirectionRef'])),
            self::string(self::path($journey, ['FramedVehicleJourneyRef', 'DatedVehicleJourneyRef'])),
            $status,
        );
    }

    /** @param array<mixed> $message */
    private function alert(array $message): ServiceAlert
    {
        $content = self::array($message['Content'] ?? null);
        $header = $description = [];
        foreach (self::list($content['Message'] ?? null) as $part) {
            $text = self::array($part['MessageText'] ?? null);
            $value = self::string($text['value'] ?? null);
            if (null === $value) {
                continue;
            }
            $lang = self::string($text['lang'] ?? null) ?? '';
            if ('SHORT_MESSAGE' === ($part['MessageType'] ?? null)) {
                $header[$lang] ??= $value;
            } else {
                $description[$lang] ??= $value;
            }
        }
        $entities = [];
        foreach (self::list($content['LineRef'] ?? null) as $ref) {
            $line = self::value($ref);
            if (null !== $line && null !== $route = $this->gtfsId($line, $this->lineRef)) {
                $entities[] = ['agency' => null, 'route' => $route, 'routeType' => null, 'stop' => null, 'trip' => null, 'direction' => null];
            }
        }
        foreach (self::list($content['StopPointRef'] ?? null) as $ref) {
            $stop = self::value($ref);
            if (null !== $stop && null !== $id = $this->gtfsId($stop, $this->stopRef)) {
                $entities[] = ['agency' => null, 'route' => null, 'routeType' => null, 'stop' => $id, 'trip' => null, 'direction' => null];
            }
        }
        $until = self::string($message['ValidUntilTime'] ?? null);
        $end = null === $until ? false : strtotime($until);
        $channel = strtolower(self::value($message['InfoChannelRef'] ?? null) ?? '');

        return new ServiceAlert(
            self::value($message['InfoMessageIdentifier'] ?? null) ?? self::string($message['ItemIdentifier'] ?? null) ?? sha1(serialize($header)),
            $header, $description, [], false === $end ? [] : [[null, $end]], $entities,
            effect: 'perturbation' === $channel ? 'other' : 'unknown',
            severity: 'perturbation' === $channel ? 'warning' : 'info',
            source: 'siri',
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function read(string $url, array $query, ?int $ttl = null): array
    {
        $ttl ??= $this->ttl;
        $key = $url.'?'.http_build_query($query);
        $now = time();
        if (isset($this->answers[$key]) && $now - $this->answers[$key][0] < $ttl) {
            return $this->answers[$key][1];
        }
        $file = null === $this->cache ? null : rtrim($this->cache, '/').'/'.$this->feed.'-siri-'.substr(sha1($key), 0, 16).'.json';
        $body = null !== $file && is_file($file) && $now - (int) filemtime($file) < $ttl ? (string) file_get_contents($file) : null;
        if (null === $body) {
            $body = $this->http->bytes($url, $query, ['Accept' => 'application/json']) ?? '';
            if (null !== $file) {
                if (!is_dir(\dirname($file))) {
                    @mkdir(\dirname($file), 0775, true);
                }
                $temporary = $file.'.'.getmypid().'.tmp';
                if (false !== @file_put_contents($temporary, $body)) {
                    @rename($temporary, $file);
                }
            }
        }
        $data = '' === $body ? [] : json_decode($body, true);
        if (!\is_array($data)) {
            throw new ProviderException(\sprintf('SIRI Lite (%s) answered something that is not JSON.', $this->feed));
        }
        $this->answers[$key] = [$now, $data];

        return $data;
    }

    private function local(string $id): string
    {
        return '' !== $this->prefix && str_starts_with($id, $this->prefix) ? substr($id, \strlen($this->prefix)) : $id;
    }

    private static function fill(string $template, string $id): string
    {
        return str_replace('{id}', $id, $template);
    }

    /**
     * @param list<string> $keys
     */
    private static function path(mixed $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /** @return list<array<mixed>> a SIRI list (or a lone object, as some servers write one) */
    private static function list(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        if (!array_is_list($value)) {
            return [$value];
        }

        return array_values(array_filter($value, '\is_array'));
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** A reference: {"value": "..."} or the string itself. */
    private static function value(mixed $value): ?string
    {
        return self::string(\is_array($value) ? ($value['value'] ?? null) : $value);
    }

    /** A natural-language text: [{"value": "..."}], {"value": "..."} or the string itself. */
    private static function text(mixed $value): ?string
    {
        if (\is_array($value) && array_is_list($value)) {
            $value = $value[0] ?? null;
        }

        return self::value($value);
    }
}

<?php

namespace Omnitravel\Gtfs\Realtime;

use Omnitravel\Gtfs\ServiceAlert;

/**
 * A GTFS-Realtime Alert, read from its bytes: active_period (1),
 * informed_entity (5), cause (6), effect (7), url (8), header_text (10),
 * description_text (11), severity_level (14); the texts' translations by
 * language.
 */
final class Alert
{
    public static function parse(string $id, string $message): ServiceAlert
    {
        $periods = $entities = $header = $description = $url = [];
        $cause = $effect = $severity = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            match ($number) {
                1 => $periods[] = self::period((string) $value),
                5 => $entities[] = self::entity((string) $value),
                6 => $cause = (int) $value,
                7 => $effect = (int) $value,
                8 => $url = self::translated((string) $value),
                10 => $header = self::translated((string) $value),
                11 => $description = self::translated((string) $value),
                14 => $severity = (int) $value,
                default => null,
            };
        }

        return new ServiceAlert(
            $id, $header, $description, $url, $periods, $entities,
            ServiceAlert::CAUSES[$cause ?? 1] ?? 'unknown', ServiceAlert::EFFECTS[$effect ?? 8] ?? 'unknown', ServiceAlert::SEVERITIES[$severity ?? 1] ?? 'unknown',
        );
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function period(string $message): array
    {
        $start = $end = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            if (1 === $number && 0 !== (int) $value) {
                $start = (int) $value;
            } elseif (2 === $number && 0 !== (int) $value) {
                $end = (int) $value;
            }
        }

        return [$start, $end];
    }

    /** @return array{agency: ?string, route: ?string, routeType: ?int, stop: ?string, trip: ?string, direction: ?int} */
    private static function entity(string $message): array
    {
        $entity = ['agency' => null, 'route' => null, 'routeType' => null, 'stop' => null, 'trip' => null, 'direction' => null];
        $tripRoute = null;
        foreach (WireReader::fields($message) as [$number, , $value]) {
            match ($number) {
                1 => $entity['agency'] = (string) $value,
                2 => $entity['route'] = (string) $value,
                3 => $entity['routeType'] = WireReader::int32((int) $value),
                4 => [$entity['trip'], $tripRoute] = [($trip = TripDescriptor::parse((string) $value))->tripId, $trip->routeId],
                5 => $entity['stop'] = (string) $value,
                6 => $entity['direction'] = (int) $value,
                default => null,
            };
        }
        // A trip descriptor may name its route: the alert then concerns that line too.
        $entity['route'] ??= $tripRoute;

        return $entity;
    }

    /** @return array<string, string> a TranslatedString's texts by language */
    private static function translated(string $message): array
    {
        $texts = [];
        foreach (WireReader::fields($message) as [$number, , $value]) {
            if (1 !== $number) {
                continue;
            }
            $text = $language = '';
            foreach (WireReader::fields((string) $value) as [$n, , $v]) {
                if (1 === $n) {
                    $text = (string) $v;
                } elseif (2 === $n) {
                    $language = (string) $v;
                }
            }
            $texts[$language] ??= $text;
        }

        return $texts;
    }
}

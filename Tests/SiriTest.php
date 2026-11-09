<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Siri\SiriLite;
use Omnitravel\Gtfs\Siri\Visit;
use Omnitravel\Http\JsonClient;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Testing\RecordedHttpClient;

/**
 * SIRI Lite laid over the fixture feed (answers written as a network would
 * give them), and Île-de-France Mobilités' PRIM answers for Châtelet and
 * metro line 1 recorded on 2026-11-09 at 23:18 UTC (the key left out).
 */
final class SiriTest extends GtfsTestCase
{
    private const OPTIONS = [
        'siri_stop_monitoring_url' => 'https://siri.example.org/stop-monitoring',
        'siri_general_message_url' => 'https://siri.example.org/general-message',
        'siri_stop_ref' => 'TEST:StopPoint:Q:{id}:',
        'siri_stop_area_ref' => 'TEST:StopArea:SP:{id}:',
        'siri_line_ref' => 'TEST:Line::{id}:',
        'siri_headers' => ['apikey' => 'not-a-real-key'],
    ];

    public function testAnnouncedVisitsReplaceTheirLinesScheduledCallsAtTheirPlatform(): void
    {
        $http = RecordedHttpClient::routes(['~stop-monitoring~' => [200, self::stopMonitoring()]]);
        $provider = self::provider(self::OPTIONS, $http);
        $departures = $provider->departures(new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 6));

        self::assertSame([
            ['08:05', '3', 'CENTRAL_2', false, false, null],          // B1: SIRI says nothing of line 3
            ['08:12', 'A', 'CENTRAL_2', false, false, null],          // A3: the other platform, untouched
            ['08:13', 'A', 'CENTRAL_1', true, false, 180],            // A1, announced 3 minutes late
            ['08:29', 'A', 'CENTRAL_1', true, true, 0],               // A2, announced cancelled (no aimed time given)
            ['09:05', '3', 'CENTRAL_2', false, false, null],          // B2
            ['01:00', 'A', 'CENTRAL_1', false, false, null],          // A9: after the last announced, the timetable again
        ], array_map(static fn (Departure $d) => [$d->time()->format('H:i'), $d->line?->name, $d->stop->id, $d->realtime, $d->cancelled, $d->delay()], $departures));
        self::assertSame('Roseraie (SIRI)', $departures[2]->headsign);
        self::assertSame('TEST:StopArea:SP:CENTRAL:', self::query($http, 0, 'MonitoringRef'), 'a station by its stop area');
        self::assertSame('not-a-real-key', $http->header('apikey'));
        self::assertTrue($provider->capabilities()->realtime);
    }

    public function testWithoutAKeyTheTimetableStaysAndSaysWhy(): void
    {
        $provider = self::provider(self::OPTIONS, RecordedHttpClient::routes(['~stop-monitoring~' => [401, '{"message":"No API key found in request"}']]));
        $departures = $provider->departures(new DepartureQuery('CENTRAL_1', self::at('2026-11-09 07:55'), 2));

        self::assertSame(['08:10', '08:30'], array_map(static fn (Departure $d) => $d->time()->format('H:i'), $departures));
        self::assertFalse($departures[0]->realtime);
        self::assertStringContainsString('401', (string) $provider->realtimeError());
    }

    public function testAGeneralMessageIsAServiceAlertOfItsLine(): void
    {
        $message = ['Siri' => ['ServiceDelivery' => ['GeneralMessageDelivery' => [['InfoMessage' => [[
            'InfoMessageIdentifier' => ['value' => 'MSG-1'], 'InfoChannelRef' => ['value' => 'Perturbation'], 'ValidUntilTime' => '2026-11-09T10:00:00Z',
            'Content' => ['LineRef' => [['value' => 'TEST:Line::A:']], 'Message' => [['MessageType' => 'SHORT_MESSAGE', 'MessageText' => ['value' => 'Trafic perturbé', 'lang' => 'fr']]]],
        ]]]]]]];
        $http = RecordedHttpClient::routes(['~general-message~' => [200, (string) json_encode($message)]]);
        $alerts = self::provider(self::OPTIONS, $http)->alerts(['A'], ['CENTRAL_1'], self::at('2026-11-09 08:00'));

        self::assertCount(1, $alerts);
        self::assertSame(['MSG-1', 'Trafic perturbé', 'warning', 'siri', ['A']], [$alerts[0]->id, $alerts[0]->headerText(), $alerts[0]->severity, $alerts[0]->source, $alerts[0]->routes()]);
        self::assertSame('TEST:Line::A:', self::query($http, 0, 'LineRef'));
        self::assertSame([], self::provider(self::OPTIONS, $http)->alerts(['A'], [], self::at('2026-11-09 12:00')), 'over after its ValidUntilTime');
    }

    public function testPrimsAnswersAreReadIntoTheFeedsIdentifiers(): void
    {
        $http = RecordedHttpClient::routes([
            '~stop-monitoring~' => [200, (string) file_get_contents(__DIR__.'/Fixtures/prim-stop-monitoring.json')],
            '~general-message~' => [200, (string) file_get_contents(__DIR__.'/Fixtures/prim-general-message.json')],
        ]);
        $siri = new SiriLite(new JsonClient($http, 'PRIM'), 'https://prim.iledefrance-mobilites.fr/marketplace/stop-monitoring', 'https://prim.iledefrance-mobilites.fr/marketplace/general-message',
            'STIF:StopPoint:Q:{id}:', 'STIF:StopArea:SP:{id}:', 'STIF:Line::{id}:', 'IDFM:');
        $visits = $siri->visits('IDFM:71264', true);

        self::assertSame('STIF:StopArea:SP:71264:', self::query($http, 0, 'MonitoringRef'));
        self::assertSame(
            [['IDFM:C01371', 'IDFM:22087', 'Château de Vincennes', '23:24:18'], ['IDFM:C01374', 'IDFM:22092', 'Bagneux - Lucie Aubrac', '23:20:44']],
            array_map(static fn (Visit $v) => [$v->line, $v->stop, $v->destination, gmdate('H:i:s', (int) $v->time())], [$visits[0], $visits[3]]),
        );
        self::assertSame('Retour', $visits[0]->direction);
        self::assertFalse($visits[0]->atStop);

        $alerts = $siri->messages(['IDFM:C01371']);
        self::assertSame('STIF:Line::C01371:', self::query($http, 1, 'LineRef'));
        self::assertSame(['IDFM:C01371'], $alerts[0]->routes());
        self::assertStringStartsWith('Le 11 novembre, arrêts non desservis', $alerts[0]->headerText('fr'));
        self::assertStringStartsWith('Le 11 novembre, les arrêts ne seront pas desservis', (string) $alerts[0]->descriptionText('fr'));
        self::assertNull($siri->gtfsId('OTHER:Line::C01371:', 'STIF:Line::{id}:'));
    }

    /** Line A at the station's first platform: A1 three minutes late, A2 cancelled. */
    private static function stopMonitoring(): string
    {
        $visit = static fn (string $aimed, string $expected, string $status, string $destination) => [
            'MonitoringRef' => ['value' => 'TEST:StopPoint:Q:CENTRAL_1:'],
            'MonitoredVehicleJourney' => [
                'LineRef' => ['value' => 'TEST:Line::A:'], 'DirectionRef' => ['value' => 'Aller'],
                'MonitoredCall' => array_filter(['AimedDepartureTime' => $aimed, 'ExpectedDepartureTime' => $expected, 'DepartureStatus' => $status, 'DestinationDisplay' => [['value' => $destination]], 'VehicleAtStop' => false]),
            ],
        ];

        return (string) json_encode(['Siri' => ['ServiceDelivery' => ['StopMonitoringDelivery' => [['MonitoredStopVisit' => [
            $visit('2026-11-09T07:10:00Z', '2026-11-09T07:13:00Z', 'delayed', 'Roseraie (SIRI)'),
            $visit('', '2026-11-09T07:29:00Z', 'cancelled', 'Roseraie'),
        ]]]]]]);
    }

    /** A request's query parameter, given as an option or in the URL. */
    private static function query(RecordedHttpClient $http, int $request, string $name): ?string
    {
        $made = $http->requests[$request] ?? [];
        $query = \is_array($made['options']['query'] ?? null) ? $made['options']['query'] : [];
        if (!isset($query[$name])) {
            parse_str((string) parse_url((string) ($made['url'] ?? ''), \PHP_URL_QUERY), $query);
        }

        return isset($query[$name]) && \is_string($query[$name]) ? $query[$name] : null;
    }
}

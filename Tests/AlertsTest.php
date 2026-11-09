<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\Realtime\FeedMessage;
use Omnitravel\Gtfs\Realtime\WireWriter as W;
use Omnitravel\Gtfs\ServiceAlert;
use Omnitravel\Testing\RecordedHttpClient;

/**
 * GTFS-Realtime's service alerts: a feed written with WireWriter as a
 * network would publish it, and Angers' Irigo alerts (ODbL, served by
 * notify.ratpdev.com as "application/json" though its body is Protocol
 * Buffers) recorded on 2026-11-10.
 */
final class AlertsTest extends GtfsTestCase
{
    private const ALERTS = 'https://rt.example.org/alerts';

    public function testAnAlertIsReadWithItsTextsPeriodsAndWhatItConcerns(): void
    {
        $alerts = FeedMessage::parse(self::feed())->alerts;

        self::assertCount(3, $alerts);
        [$works, $station, $network] = $alerts;
        self::assertSame('works-a', $works->id);
        self::assertSame('Tram A: travaux entre Molière et Ralliement', $works->headerText('fr'));
        self::assertSame('Tram A: works between Molière and Ralliement', $works->headerText('en'));
        self::assertSame('Tram A: travaux entre Molière et Ralliement', $works->headerText('ja'), 'French, then English, then the first given');
        self::assertSame('Un bus remplace le tram.', $works->descriptionText('fr'));
        self::assertSame('https://transit.example.org/travaux', $works->urlFor());
        self::assertSame(['construction', 'reduced-service', 'warning'], [$works->cause, $works->effect, $works->severity]);
        self::assertSame([[1794200000, 1794300000]], $works->periods);
        self::assertSame(['A'], $works->routes());
        self::assertSame('CENTRAL', $station->entities[0]['stop']);
        self::assertSame('TEST', $network->entities[0]['agency']);
    }

    public function testABoardGetsTheAlertsOfItsLinesItsStationAndTheNetworkInForce(): void
    {
        $provider = self::provider(['alerts_url' => self::ALERTS], RecordedHttpClient::routes(['~/alerts~' => [200, self::feed()]]));
        $ids = self::ids(...);
        $at = new \DateTimeImmutable('@1794250000');

        self::assertSame(['works-a', 'lift', 'strike'], $ids($provider->alerts(['A'], ['CENTRAL_1'], $at)), 'a platform is its station');
        self::assertSame(['lift', 'strike'], $ids($provider->alerts(['3'], ['CENTRAL_2'], $at)), 'another line: not tram A\'s works');
        self::assertSame(['strike'], $ids($provider->alerts(['3'], ['FAR'], $at)));
        self::assertSame(['lift', 'strike'], $ids($provider->alerts(['A'], ['CENTRAL'], new \DateTimeImmutable('@1794400000'))), 'the works are over');
        self::assertSame(['works-a', 'lift', 'strike'], $ids($provider->alerts(at: $at)), 'every one in force');
    }

    public function testAnAlertOnATripConcernsItsLine(): void
    {
        $alert = W::bytes(5, W::bytes(4, W::bytes(1, 'A1').W::bytes(5, 'A'))).W::bytes(10, W::bytes(1, W::bytes(1, 'Course supprimée')));
        $alerts = FeedMessage::parse(W::bytes(2, W::bytes(1, 'trip').W::bytes(5, $alert)))->alerts;

        self::assertSame('A1', $alerts[0]->entities[0]['trip']);
        self::assertTrue($alerts[0]->concerns(['A'], ['RALL']));
        self::assertFalse($alerts[0]->concerns(['3'], ['RALL']));
    }

    public function testAFeedThatCannotBeReadGivesNoAlertAndSaysWhy(): void
    {
        $provider = self::provider(['alerts_url' => self::ALERTS], RecordedHttpClient::routes(['~/alerts~' => [503, 'Down']]));

        self::assertSame([], $provider->alerts(['A']));
        self::assertNotNull($provider->realtimeError());
    }

    public function testAngersAlertsAreReadThoughServedAsJson(): void
    {
        $alerts = FeedMessage::parse((string) file_get_contents(__DIR__.'/Fixtures/angers-alerts.pb'))->alerts;

        self::assertNotEmpty($alerts);
        foreach ($alerts as $alert) {
            self::assertNotSame('', $alert->id);
            self::assertNotSame('', $alert->headerText('fr'));
            self::assertNotSame([], $alert->entities);
        }
    }

    /** Three alerts: tram A's works for a while, a lift out at the station, a strike on the whole network. */
    private static function feed(): string
    {
        $text = self::translated(...);
        $works = W::bytes(1, W::varint(1, 1794200000).W::varint(2, 1794300000))
            .W::bytes(5, W::bytes(2, 'A'))
            .W::varint(6, 10).W::varint(7, 2)
            .$text(8, ['' => 'https://transit.example.org/travaux'])
            .$text(10, ['fr' => 'Tram A: travaux entre Molière et Ralliement', 'en' => 'Tram A: works between Molière and Ralliement'])
            .$text(11, ['fr' => 'Un bus remplace le tram.'])
            .W::varint(14, 3);
        $lift = W::bytes(5, W::bytes(5, 'CENTRAL')).W::varint(7, 11).$text(10, ['fr' => 'Ascenseur hors service']);
        $strike = W::bytes(5, W::bytes(1, 'TEST')).W::varint(6, 4).$text(10, ['fr' => 'Mouvement social']);

        return W::bytes(1, W::bytes(1, '2.0').W::varint(3, 1794250000))
            .W::bytes(2, W::bytes(1, 'works-a').W::bytes(5, $works))
            .W::bytes(2, W::bytes(1, 'lift').W::bytes(5, $lift))
            .W::bytes(2, W::bytes(1, 'gone').W::varint(2, 1).W::bytes(5, $strike))
            .W::bytes(2, W::bytes(1, 'strike').W::bytes(5, $strike));
    }

    /**
     * @param list<ServiceAlert> $alerts
     *
     * @return list<string>
     */
    private static function ids(array $alerts): array
    {
        return array_map(static fn (ServiceAlert $alert): string => $alert->id, $alerts);
    }

    /** @param array<string, string> $byLanguage */
    private static function translated(int $field, array $byLanguage): string
    {
        $translations = '';
        foreach ($byLanguage as $lang => $text) {
            $translations .= W::bytes(1, W::bytes(1, $text).('' === $lang ? '' : W::bytes(2, $lang)));
        }

        return W::bytes($field, $translations);
    }
}

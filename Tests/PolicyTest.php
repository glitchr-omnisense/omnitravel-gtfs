<?php

namespace Omnitravel\Gtfs\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitravel\Exception\UsageLimitReachedException;
use Omnitravel\Gtfs\GtfsFactory;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Registry;

/** A feed's policy is the site's to give: its licence and credit where it configures the feed, a tightening on top. */
final class PolicyTest extends GtfsTestCase
{
    public function testAFeedsLicenceAndCreditComeFromItsConfiguration(): void
    {
        $registry = self::registry(['licence' => 'ODbL-1.0', 'attribution' => 'Example Transit, ODbL']);
        $policy = $registry->policy('angers');

        self::assertSame('gtfs', $policy->provider);
        self::assertSame('ODbL-1.0', $policy->rights->licence);
        self::assertTrue($policy->rights->attribution->required);
        self::assertSame('Example Transit, ODbL', $policy->rights->attribution->text);
        self::assertNull($policy->rateLimits, 'a feed\'s limits are its publisher\'s, unknown');
        self::assertFalse($policy->requirements->key);
        self::assertSame('ODbL-1.0', $registry->get('angers')->capabilities()->licence);
    }

    public function testASiteTightensAFeedsPolicy(): void
    {
        $registry = self::registry(['licence' => 'ODbL-1.0', 'policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute', 'operations' => ['departures']]], 'rights' => ['redistribution' => 'forbidden']]]);
        $query = new DepartureQuery('CENTRAL', self::at('2026-11-09 07:55'), 2);

        self::assertSame('forbidden', $registry->policy('angers')->rights->redistribution->value);
        $registry->timetables('angers')->departures($query);
        $registry->timetables('angers')->departures($query);
        $this->expectException(UsageLimitReachedException::class);
        $registry->timetables('angers')->departures($query);
    }

    public function testThePackagesPolicySaysItIsPerFeed(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('gtfs', $policy->provider);
        self::assertNull($policy->rateLimits);
        self::assertStringContainsString('Per feed', (string) $policy->provenance->notes);
    }

    /** @param array<string, mixed> $options */
    private static function registry(array $options): Registry
    {
        $factory = new GtfsFactory();
        (new \ReflectionProperty(GtfsFactory::class, 'stores'))->setValue($factory, ['sqlite::memory:' => self::store()]);

        return new Registry([$factory], ['angers' => ['factory' => 'gtfs', 'options' => $options + ['feed' => 'test', 'dsn' => 'sqlite::memory:']]]);
    }
}

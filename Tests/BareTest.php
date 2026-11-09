<?php

namespace Omnitravel\Gtfs\Tests;

use Omnitravel\Gtfs\GtfsProvider;
use Omnitravel\Testing\BareTestCase;

/** omnitravel/gtfs's classes load with omnisense/omnitravel and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return GtfsProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}

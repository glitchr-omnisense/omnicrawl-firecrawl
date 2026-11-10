<?php

namespace Omnicrawl\Firecrawl\Tests;

use Omnicrawl\Firecrawl\FirecrawlProvider;
use Omnicrawl\Testing\BareTestCase;

/** omnicrawl/firecrawl's classes load with omnisense/omnicrawl, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return FirecrawlProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}

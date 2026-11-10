<?php

namespace Omnicrawl\Firecrawl\Tests;

use Omnicrawl\Agent;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\TargetRefusedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Firecrawl\FirecrawlFactory;
use Omnicrawl\Firecrawl\FirecrawlProvider;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\RunStatus;
use Omnicrawl\Registry;
use Omnicrawl\Secrets\ArraySecrets;
use Omnicrawl\Target\Targets;
use Omnicrawl\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\Permission;
use PHPUnit\Framework\TestCase;

/** Firecrawl's v2 calls as its API reference writes them, answered from recordings: no live call. */
final class FirecrawlProviderTest extends TestCase
{
    private const UA = 'ExampleBot/1.0 (+https://example.org/bot; bot@example.org)';

    private const DOCS = ['target' => 'docs.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://docs.example.org', 'date' => '2026-11-10', 'operations' => ['page-fetch', 'extract', 'crawl-runs'], 'render' => true, 'rateLimits' => [['max' => 60, 'window' => 'minute']]];

    private static function registry(RecordedHttpClient $api, ?RecordedHttpClient $site = null): Registry
    {
        $site ??= RecordedHttpClient::routes(['~/robots\.txt$~' => [200, "User-agent: *\nDisallow: /admin/\n"]]);

        return new Registry([new FirecrawlFactory($api, new ArraySecrets(['FIRECRAWL_API_KEY' => 'fc-not-a-real-key']))], ['firecrawl' => ['factory' => 'firecrawl', 'options' => ['api_key' => 'FIRECRAWL_API_KEY']]], new Gate(Agent::parse(self::UA), $site, new Targets([self::DOCS])));
    }

    /** @return array<mixed> the JSON body of the service's first request */
    private static function sent(RecordedHttpClient $api): array
    {
        $first = $api->requests[0] ?? null;
        $sent = null === $first ? null : json_decode(RecordedHttpClient::body($first['options']), true);
        self::assertIsArray($sent);

        return $sent;
    }

    public function testAPageIsScrapedRawFreshAndUndisguised(): void
    {
        $api = RecordedHttpClient::routes(['~^POST https://api\.firecrawl\.dev/v2/scrape$~' => [200, '@'.__DIR__.'/Fixtures/scrape.json']]);

        $record = self::registry($api)->pageFetch('firecrawl')->fetch('https://docs.example.org/');

        $sent = self::sent($api);
        self::assertSame([
            'url' => 'https://docs.example.org/',
            'formats' => ['rawHtml'],
            'onlyMainContent' => false,
            'headers' => ['User-Agent' => self::UA.' omnicrawl/1'],
            'proxy' => 'basic',
            'maxAge' => 0,
            'storeInCache' => false,
            'blockAds' => false,
        ], $sent);
        self::assertSame('Bearer fc-not-a-real-key', $api->header('Authorization'));
        self::assertSame('https://docs.example.org/en/', $record->finalUrl);
        self::assertSame(200, $record->status);
        self::assertTrue($record->rendered);
        self::assertNull($record->headers);
        self::assertFalse($record->signals->headersSeen);
        self::assertTrue($record->signals->noarchive());
        self::assertSame(Permission::Forbidden, $record->rights->storage);
        self::assertSame('firecrawl', $record->provenance->provider);
    }

    public function testAnUndeclaredSiteIsRefusedBecauseFirecrawlAlwaysRenders(): void
    {
        $api = RecordedHttpClient::routes([]);

        try {
            self::registry($api)->pageFetch('firecrawl')->fetch('https://news.example.com/1');
            self::fail('Firecrawl rendered an undeclared site.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_RENDERING, $e->reason);
        }
        self::assertSame([], $api->requests, 'Firecrawl was not called');
    }

    public function testABatchRunOverTheAdmittedAddressesOnly(): void
    {
        $api = RecordedHttpClient::routes([
            '~^POST https://api\.firecrawl\.dev/v2/batch/scrape$~' => [200, '@'.__DIR__.'/Fixtures/batch-start.json'],
            '~^GET https://api\.firecrawl\.dev/v2/batch/scrape/9f3c2a1e-0000-4000-8000-000000000001\?skip=2$~' => [200, '@'.__DIR__.'/Fixtures/batch-2.json'],
            '~^GET https://api\.firecrawl\.dev/v2/batch/scrape/9f3c2a1e-0000-4000-8000-000000000001$~' => [200, '@'.__DIR__.'/Fixtures/batch-1.json'],
        ]);
        $runs = self::registry($api)->crawlRuns('firecrawl');

        try {
            $runs->start(['https://docs.example.org/a', 'https://docs.example.org/admin/']);
            self::fail('A run with a disallowed address started.');
        } catch (RefusedException $e) {
            self::assertSame(RefusedException::REASON_ROBOTS, $e->reason);
        }
        self::assertSame([], $api->requests);

        $run = $runs->start(['https://docs.example.org/a', 'https://docs.example.org/b']);
        $sent = self::sent($api);
        self::assertSame(['https://docs.example.org/a', 'https://docs.example.org/b'], $sent['urls']);
        self::assertSame([1, false, 'basic', ['User-Agent' => self::UA.' omnicrawl/1']], [$sent['maxConcurrency'], $sent['ignoreInvalidURLs'], $sent['proxy'], $sent['headers']]);
        self::assertSame('9f3c2a1e-0000-4000-8000-000000000001', $run->id);

        $status = $runs->run($run->id);
        self::assertSame([RunStatus::Running, 3, 2], [$status->status, $status->total, $status->completed]);

        $first = $runs->results($run->id);
        self::assertSame(['https://docs.example.org/a'], array_map(static fn ($r) => $r->url, $first->records));
        self::assertSame([['https://docs.example.org/admin/', RefusedException::REASON_ROBOTS]], array_map(static fn (array $r) => [$r['url'], $r['reason']], $first->refused), 'a page Firecrawl brought back that robots.txt disallows is dropped');
        $second = $runs->results($run->id, $first->next);
        self::assertSame([404], array_map(static fn ($r) => $r->status, $second->records));
        self::assertNull($second->next);
    }

    public function testACursorElsewhereIsRefused(): void
    {
        $this->expectException(\Omnicrawl\Exception\InvalidArgumentException::class);
        $provider = self::registry(RecordedHttpClient::routes([]))->get('firecrawl');
        self::assertInstanceOf(FirecrawlProvider::class, $provider);
        $provider->results('x', 'https://elsewhere.example.net/steal?key');
    }

    public function testThePolicyAndA429(): void
    {
        $api = RecordedHttpClient::routes(['~/scrape$~' => [429, '{"error":"Rate limit exceeded"}', ['retry-after' => ['30']]]]);
        $registry = self::registry($api);
        $policy = $registry->policy('firecrawl');

        self::assertSame(['10/minute/application/fetch', '2/minute/application/start'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame(Permission::Contract, $policy->rights->commercial);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        try {
            $registry->pageFetch('firecrawl')->fetch('https://docs.example.org/');
            self::fail('A 429 was read as a page.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
            self::assertStringNotContainsString('fc-not-a-real-key', $e->getMessage());
        }
    }
}

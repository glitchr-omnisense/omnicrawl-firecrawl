<?php

namespace Omnicrawl\Firecrawl;

use Omnicrawl\Capabilities;
use Omnicrawl\Exception\InvalidArgumentException;
use Omnicrawl\Exception\ProviderException;
use Omnicrawl\FetcherInterface;
use Omnicrawl\Http\Data;
use Omnicrawl\Http\JsonClient;
use Omnicrawl\Model\CrawlRequest;
use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\FetchedPage;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\RunStatus;
use Omnicrawl\RunnerInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Firecrawl (API v2), as the family uses it:
 *
 * - a page: POST /scrape, the target's raw HTML ("rawHtml"), the whole
 *   page (onlyMainContent false), its status and final address
 *   (metadata.statusCode, metadata.url);
 * - a run: POST /batch/scrape over exactly the addresses admitted - a
 *   batch follows no link -, one at a time (maxConcurrency 1); its status
 *   and pages from GET /batch/scrape/{id}, the "next" address followed
 *   for the following ones.
 *
 * Every request carries the family's User-Agent for the target
 * ("headers"), proxy "basic" - never "enhanced" or "auto", which retries
 * through Firecrawl's anti-bot proxies when the basic one is refused -,
 * maxAge 0 and storeInCache false (a fresh fetch, no copy left in
 * Firecrawl's cache), blockAds false (no rewriting of the target's page).
 * No "actions", "location" or "mobile": nothing that disguises the
 * request. Firecrawl renders pages in a browser and has no switch to
 * turn it off: the provider declares it renders always, so that the gate
 * admits it only where rendering is allowed. It does not report the
 * target's headers: X-Robots-Tag and the tdm-reservation header are
 * unknown on its records (Signals::$headersSeen false).
 */
final class FirecrawlProvider implements FetcherInterface, RunnerInterface, DeclaresUsagePolicyInterface
{
    public const BASE_URI = 'https://api.firecrawl.dev/v2';

    /** Firecrawl's terms and limits as read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    public function __construct(private readonly JsonClient $client, private readonly string $base = self::BASE_URI)
    {
    }

    public function getName(): string
    {
        return 'firecrawl';
    }

    public function getTitle(): string
    {
        return 'Firecrawl';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(pageFetch: true, crawlRuns: true, rendering: true, headers: false, policy: $this->usagePolicy(), alwaysRenders: true);
    }

    public function fetch(FetchRequest $request): Fetched
    {
        $data = $this->client->request('POST', $this->base.'/scrape', ['json' => ['url' => $request->url] + self::options($request->agent->header())]);

        return self::page($data?->data('data') ?? Data::of([]), $request->url) ?? throw ProviderException::answered('Firecrawl', 'POST /scrape', 200, 'No page in the answer.');
    }

    public function start(CrawlRequest $request): CrawlRun
    {
        $data = $this->client->request('POST', $this->base.'/batch/scrape', ['json' => ['urls' => $request->urls, 'maxConcurrency' => 1, 'ignoreInvalidURLs' => false] + self::options($request->agent->header())]);
        $id = $data?->string('id') ?? throw ProviderException::answered('Firecrawl', 'POST /batch/scrape', 200, 'No run id in the answer.');

        return new CrawlRun($id, RunStatus::Queued, 'firecrawl', \count($request->urls), 0);
    }

    public function run(string $id): CrawlRun
    {
        $data = $this->client->get($this->base.'/batch/scrape/'.rawurlencode($id)) ?? throw ProviderException::answered('Firecrawl', 'GET /batch/scrape/{id}', 404, 'No such run.');

        return new CrawlRun($id, self::status($data->string('status')), 'firecrawl', $data->int('total'), $data->int('completed'));
    }

    public function results(string $id, ?string $cursor = null): FetchedPage
    {
        $url = $this->base.'/batch/scrape/'.rawurlencode($id);
        if (null !== $cursor) {
            // The "next" address Firecrawl gave: followed only on its own host, where the key may go.
            if (parse_url($cursor, \PHP_URL_HOST) !== parse_url($this->base, \PHP_URL_HOST) || 'https' !== parse_url($cursor, \PHP_URL_SCHEME)) {
                throw new InvalidArgumentException('A Firecrawl cursor is an address on Firecrawl\'s API.');
            }
            $url = $cursor;
        }
        $data = $this->client->get($url) ?? throw ProviderException::answered('Firecrawl', 'GET /batch/scrape/{id}', 404, 'No such run.');
        $items = [];
        foreach ($data->list('data') as $one) {
            $page = self::page($one, null);
            if (null !== $page) {
                $items[] = $page;
            }
        }

        return new FetchedPage($items, $data->string('next'));
    }

    /** @return array<string, mixed> what every scrape asks: the raw page, fresh, through the basic proxy, with the family's User-Agent */
    private static function options(string $userAgent): array
    {
        return [
            'formats' => ['rawHtml'],
            'onlyMainContent' => false,
            'headers' => ['User-Agent' => $userAgent],
            'proxy' => 'basic',
            'maxAge' => 0,
            'storeInCache' => false,
            'blockAds' => false,
        ];
    }

    private static function page(Data $page, ?string $asked): ?Fetched
    {
        $url = $asked ?? $page->string('metadata.sourceURL');
        $status = $page->int('metadata.statusCode');
        if (null === $url || null === $status) {
            return null;
        }

        return new Fetched($url, $page->string('metadata.url') ?? $url, $status, $page->string('rawHtml') ?? '', null, true, null !== $page->string('rawHtml'));
    }

    private static function status(?string $status): RunStatus
    {
        return match ($status) {
            'completed' => RunStatus::Succeeded,
            'failed' => RunStatus::Failed,
            'cancelled' => RunStatus::Cancelled,
            'scraping' => RunStatus::Running,
            default => RunStatus::Queued,
        };
    }
}

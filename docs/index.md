# omnicrawl/firecrawl

Firecrawl's API v2 (https://docs.firecrawl.dev/api-reference/v2-introduction), read on 2026-11-10.

| Contract | Call | Sent besides the address |
|---|---|---|
| page-fetch, extract | `POST /v2/scrape` | `formats: ["rawHtml"]`, `onlyMainContent: false`, `headers: {"User-Agent": <the family's>}`, `proxy: "basic"`, `maxAge: 0`, `storeInCache: false`, `blockAds: false` |
| crawl-runs | `POST /v2/batch/scrape`, `GET /v2/batch/scrape/{id}` (and its `next`) | the same, `maxConcurrency: 1`, `ignoreInvalidURLs: false` |

Read back: `data.rawHtml`, `data.metadata.statusCode` (the target's status), `metadata.url` (the
final address), `metadata.sourceURL` (the address asked, in a batch); a batch's `status`
(`scraping`, `completed`, `failed`, `cancelled`), `total`, `completed`, `next`.

- **Always rendered.** Firecrawl loads pages in a browser and documents no switch to turn it off:
  the provider declares `alwaysRenders`, so the gate admits it only for targets whose declaration
  allows rendering. Undeclared sites are refused (`rendering-not-allowed`): use the direct
  provider for previews.
- **No target headers.** Firecrawl does not report them: X-Robots-Tag and the tdm-reservation
  header are unknown on its records (`Signals::$headersSeen` false); meta robots and the TDM meta
  are read from the HTML.
- **Not exposed:** `proxy: "enhanced"` and `"auto"` (the default, which retries through
  Firecrawl's anti-bot proxies when the basic one is refused), `actions`, `location`, `mobile`,
  the crawl endpoint (it follows links: a run here is a batch over the addresses admitted).
- **Cursor:** the `next` address is followed only on Firecrawl's API host.
- **Policy** (`policy.json`): the free plan's limits (10 scrapes a minute, 2 batch starts a minute,
  2 in flight); commercial use "contract" (the terms forbid it unless expressly authorised); the
  customer bears the legality of what it collects.

# omnicrawl/firecrawl

Firecrawl for [omnisense/omnicrawl](https://github.com/glitchr-omnisense/omnicrawl), under any PHP host.
Pages as raw HTML (POST /v2/scrape) and batch runs over a fixed set of addresses (POST /v2/batch/scrape), with the family's User-Agent sent to the target, the basic proxy only, no copy left in Firecrawl's cache.

It is reached through omnicrawl's gate only: every address is admitted first (the target's
declaration, robots.txt read by the core, TDM reservations, the host's limits), the service is
called after, and what it brings back is checked again and made a record with its provenance.
Read [what omnicrawl refuses to do, and why](https://github.com/glitchr-omnisense/omnicrawl/blob/1.x/docs/refusals.md).

```php
$registry = new Registry([new FirecrawlFactory($http)], [
    'firecrawl' => ['factory' => 'firecrawl', 'options' => ['api_key' => 'FIRECRAWL_API_KEY']],
], $gate);
```

The key goes by reference: `FIRECRAWL_API_KEY` names an environment variable (or a secret of the
`SecretsInterface` given to the factory), never the key itself. Its usage policy is
`policy.json` (limits, terms, what they say of lawful use), read on the day it says; the limits of each plan the service publishes are `plans.json`, picked with the `plan` option (free (default), hobby, standard, growth, scale). See
[docs/index.md](docs/index.md).

Tested on recorded answers written from the service's documentation; no test calls the service.

MIT licence.

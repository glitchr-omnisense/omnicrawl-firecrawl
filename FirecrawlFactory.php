<?php

namespace Omnicrawl\Firecrawl;

use Omnicrawl\Agent;
use Omnicrawl\Config;
use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Http\JsonClient;
use Omnicrawl\ProviderFactory;
use Omnicrawl\ProviderInterface;
use Omnicrawl\Secrets\EnvSecrets;
use Omnicrawl\Secrets\SecretsInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "firecrawl": Firecrawl's API v2. Options: api_key (the REFERENCE of the
 * key, "FIRECRAWL_API_KEY": an environment variable by default, the
 * factory's secrets otherwise - never the key itself), user_agent (the
 * registry gives the gate's), base_uri (https://api.firecrawl.dev/v2),
 * timeout (seconds, 60); policy, usage.
 */
final class FirecrawlFactory extends ProviderFactory
{
    private readonly SecretsInterface $secrets;

    public function __construct(private readonly ?HttpClientInterface $http = null, ?SecretsInterface $secrets = null)
    {
        $this->secrets = $secrets ?? new EnvSecrets();
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnicrawl.factory_name' => 'firecrawl',
            'omnicrawl.factory_title' => 'Firecrawl',
            'omnicrawl.required_options' => ['api_key', 'user_agent'],
            'base_uri' => FirecrawlProvider::BASE_URI,
            'timeout' => 60,
        ]);
    }

    protected function build(Config $c, Agent $agent): ProviderInterface
    {
        $base = rtrim((string) $c->string('base_uri'), '/');
        if (1 !== preg_match('~^https://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "firecrawl" provider needs an https base_uri such as %s, not "%s".', FirecrawlProvider::BASE_URI, $base));
        }
        $http = ($this->http ?? HttpClient::create())->withOptions(['timeout' => $c->float('timeout', 60.0)]);
        $key = $this->secrets->get((string) $c->string('api_key'));

        return new FirecrawlProvider(new JsonClient($http, 'Firecrawl', ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'], $c->backoff()), $base);
    }
}

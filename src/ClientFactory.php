<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel;

use Appsolutely\Sdk\Admin\AdminClient;
use Appsolutely\Sdk\Admin\AdminConfig;
use Appsolutely\Sdk\Cache\TokenLock;
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Laravel\Clock\LaravelClock;
use Appsolutely\Sdk\Laravel\Clock\LaravelSleeper;
use Appsolutely\Sdk\Laravel\Http\LaravelHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\DateFactory;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a client from the settings and Laravel's services, around the
 * cache and lock given: the container's shared client gets the configured
 * store, and a check that must not read or replace what that store keeps
 * gets a cache of its own.
 *
 * @internal
 */
final readonly class ClientFactory
{
    public function __construct(
        private Settings $settings,
        private HttpClientFactory $http,
        private DateFactory $dates,
        private LoggerInterface $logger,
    ) {}

    public function make(CacheInterface $cache, ?TokenLock $tokenLock): Client
    {
        $settings = $this->settings;
        $messages = new HttpFactory();

        return new Client(new Config(
            baseUrl: $settings->baseUrl(),
            issuer: $settings->issuer(),
            clientId: $settings->clientId(),
            clientSecret: $settings->clientSecret(),
            httpClient: new LaravelHttpClient($this->http, $settings->timeout(), $settings->connectTimeout()),
            requestFactory: $messages,
            streamFactory: $messages,
            cache: $cache,
            clock: new LaravelClock($this->dates),
            logger: $this->logger,
            clientAuthentication: $settings->clientAuthentication(),
            clockLeeway: $settings->clockLeeway(),
            retryPolicy: $settings->retryPolicy(new LaravelSleeper()),
            backendClientId: $settings->backendClientId(),
            backendClientSecret: $settings->backendClientSecret(),
            backendScopes: $settings->backendScopes(),
            backendClientAuthentication: $settings->backendClientAuthentication(),
            // The SDK applies these only to a client it finds itself;
            // passed for its checks, and applied by the client above.
            timeout: $settings->timeout(),
            connectTimeout: $settings->connectTimeout(),
            tokenLock: $tokenLock,
        ));
    }

    public function makeAdmin(CacheInterface $cache, ?TokenLock $tokenLock): AdminClient
    {
        $settings = $this->settings;
        [$issuer, $clientId, $clientSecret, $scopes] = $settings->adminCredential();
        $messages = new HttpFactory();

        return new AdminClient(new AdminConfig(
            baseUrl: $settings->baseUrl(),
            issuer: $issuer,
            clientId: $clientId,
            clientSecret: $clientSecret,
            scopes: $scopes,
            httpClient: new LaravelHttpClient($this->http, $settings->timeout(), $settings->connectTimeout()),
            requestFactory: $messages,
            streamFactory: $messages,
            cache: $cache,
            clock: new LaravelClock($this->dates),
            logger: $this->logger,
            clientAuthentication: $settings->adminClientAuthentication(),
            retryPolicy: $settings->retryPolicy(new LaravelSleeper()),
            timeout: $settings->timeout(),
            connectTimeout: $settings->connectTimeout(),
            tokenLock: $tokenLock,
        ));
    }
}

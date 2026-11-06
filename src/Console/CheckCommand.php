<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Console;

use Appsolutely\Sdk\Admin\AdminClient;
use Appsolutely\Sdk\Cache\InMemoryCache;
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Exception\ApiException;
use Appsolutely\Sdk\Exception\AppsolutelyException;
use Appsolutely\Sdk\Exception\OAuthException;
use Appsolutely\Sdk\Laravel\ClientFactory;
use Appsolutely\Sdk\Laravel\Clock\LaravelClock;
use Appsolutely\Sdk\Laravel\EnvironmentVariable;
use Appsolutely\Sdk\Laravel\Settings;
use Appsolutely\Sdk\Model\ApiVersion;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Proves the configuration reaches the site: its API answers the version
 * it serves, the cache store keeps a key, the backend client, when one is
 * set, is granted a token, and its issuer answers the discovery document
 * sign-in needs.
 *
 * @internal run as `php artisan appsolutely:check`
 */
#[AsCommand(name: 'appsolutely:check')]
final class CheckCommand extends Command
{
    /** The status the site answers for a version it has deprecated. */
    private const string STATUS_DEPRECATED = 'deprecated';

    private const string VERSION_REQUEST = 'its API version (GET /api/v1)';

    private const string BACKEND_PROOF_REQUEST = 'the backend client (GET /api/v1/client)';

    /** Each check writes one key under this prefix, apart from every key the SDK keeps. */
    private const string CACHE_PROOF_PREFIX = 'appsolutely.check.';

    /** @var string */
    protected $signature = 'appsolutely:check';

    /** @var string */
    protected $description = 'Read the Appsolutely site\'s API version, prove its cache store and backend client, and read its issuer\'s discovery document';

    public function handle(): int
    {
        try {
            // Resolved here rather than injected, so a missing setting is
            // reported like any other failure of the check.
            $settings = $this->laravel->make(Settings::class);
            if ($settings->hasLegacyApiToken()) {
                $this->components->warn('The api_token / APPSOLUTELY_API_TOKEN setting is ignored; use the administrator credential instead.');
            }
            if ($settings->adminClientConfigured()) {
                $this->laravel->make(AdminClient::class);
                $this->components->info('The administrator credential is configured.');
            } else {
                $this->components->info('The administrator client is not configured; administrator checks skipped.');
            }
            $client = $this->laravel->make(Client::class);
            $baseUrl = $settings->baseUrl();

            $version = $client->api()->version();
        } catch (ApiException $exception) {
            // No token is involved in this read: a 401 or 403 here is the
            // site, or something in front of it, refusing this host.
            return $this->failure(self::answered(self::VERSION_REQUEST, $exception));
        } catch (AppsolutelyException $exception) {
            return $this->failure(self::failed($exception));
        }

        $this->table(['Site', ''], [
            ['Base URL', $baseUrl],
            ['API version', $version->version],
            ['Status', $version->status],
            ['Deprecation', self::time($version->deprecatedAt)],
            ['Sunset', self::time($version->sunsetAt)],
            ['Successor', $version->successor ?? 'none'],
        ]);

        // Every other step here reads the site or the issuer, not the store
        // the discovery document, the keys, the backend token and the
        // handled webhook ids live in; a store that is down would otherwise
        // pass and fail on the first call that needs it.
        [$store, $failure] = $this->proveCacheStore($settings);
        $this->table(['Cache', ''], [['Cache store', $store]]);
        if ($failure !== null) {
            return $this->failure($failure);
        }

        // The issuer's discovery document is read through the sign-in
        // client; an application that never signs members in has none.
        if ($settings->signsMembersIn()) {
            try {
                $metadata = $client->oidc()->metadata();
            } catch (AppsolutelyException $exception) {
                return $this->failure(self::failed($exception));
            }

            // A table rather than dotted columns: those wrap a long URL
            // across lines, and these are meant to be copied.
            $this->table(['Provider', ''], [
                ['Issuer', $metadata->issuer],
                ['Authorization endpoint', $metadata->authorizationEndpoint],
                ['Token endpoint', $metadata->tokenEndpoint],
                ['UserInfo endpoint', $metadata->userInfoEndpoint ?? 'not published'],
                ['Revocation endpoint', $metadata->revocationEndpoint ?? 'not published'],
                ['Key set', $metadata->jwksUri],
            ]);
            $summary = 'The site and its issuer answer.';
        } else {
            $this->newLine();
            $this->components->info('Member sign-in is not configured; provider checks skipped.');
            $summary = 'The site answers.';
        }

        // The backend's token comes from the issuer's token endpoint, found
        // through its discovery document: only fetching one proves the
        // issuer and the machine client, which would otherwise first fail
        // on a call in production.
        if ($settings->backendClientId() !== null) {
            [$backend, $failure] = $this->proveBackend($settings);
            $this->table(['Backend', ''], [['Backend client', $backend]]);
            if ($failure !== null) {
                return $this->failure($failure);
            }
            $summary .= ' The backend client is accepted.';
        }

        return $this->passed($version, $summary);
    }

    /**
     * Fetches a backend token and reads the client it names, the smallest
     * call that needs one. The token comes through a client with a cache of
     * its own: one kept in the shared store, granted before a secret was
     * rotated, would prove nothing about the secret now set, and the new
     * one must not replace it there.
     *
     * @return array{string, string|null} the outcome to show and, when the check fails, why
     */
    private function proveBackend(Settings $settings): array
    {
        try {
            $client = $this->laravel->make(ClientFactory::class)->make(new InMemoryCache(new LaravelClock($this->laravel->make(DateFactory::class))), null);
            $backend = $client->backend()->client();
        } catch (ApiException $exception) {
            return ['not proven', self::answered(self::BACKEND_PROOF_REQUEST, $exception)];
        } catch (AppsolutelyException $exception) {
            return ['not proven', self::failed($exception)];
        }

        // An issuer may grant fewer scopes than asked for (RFC 6749,
        // section 3.3); a call that needs a missing one would fail later.
        $missing = array_values(array_diff($settings->backendScopes(), $backend->scopes));
        if ($missing !== []) {
            return [
                'accepted without every scope',
                sprintf('The backend client %s was not granted the configured scopes %s; register them for the client or remove them from %s.', $backend->clientId, implode(', ', $missing), EnvironmentVariable::BackendScopes->value),
            ];
        }

        return ['accepted as ' . $backend->clientId, null];
    }

    /**
     * Writes, reads back and deletes a throwaway key of its own, never one
     * the SDK keeps. A failure is named by its class only: a store's message
     * may carry its host or its credentials.
     *
     * @return array{string, string|null} the outcome to show and, when the check fails, why
     */
    private function proveCacheStore(Settings $settings): array
    {
        $name = $settings->cacheStore() ?? 'the default store';
        $key = self::CACHE_PROOF_PREFIX . Str::random(32);
        $value = Str::random(32);

        try {
            $cache = $this->laravel->make(CacheFactory::class)->store($settings->cacheStore());
            $proven = $cache->put($key, $value, 60) && $cache->get($key) === $value;
            $cache->forget($key);
        } catch (Throwable $exception) {
            return ['unreachable', sprintf('The cache store (%s, %s) could not be used: %s. Check the store or set %s to another.', $name, EnvironmentVariable::CacheStore->value, $exception::class, EnvironmentVariable::CacheStore->value)];
        }

        return $proven
            ? [sprintf('%s: written, read and deleted', $name), null]
            : ['not writable', sprintf('The cache store (%s, %s) did not keep a value written to it. Check the store or set %s to another.', $name, EnvironmentVariable::CacheStore->value, EnvironmentVariable::CacheStore->value)];
    }

    private function passed(ApiVersion $version, string $summary): int
    {
        // Still answered, so not a failure; but calls stop working at the
        // sunset, and a deployment gate should make that visible.
        $warning = self::deprecationWarning($version);
        if ($warning !== null) {
            $this->newLine();
            $this->components->warn($warning);
        }

        $this->newLine();
        $this->components->info($summary);

        return self::SUCCESS;
    }

    /**
     * Only a 4xx status is the site refusing the request; a 5xx is the
     * site failing to serve it, so any other status is stated as answered.
     */
    private static function answered(string $request, ApiException $exception): string
    {
        $refused = $exception->status >= 400 && $exception->status < 500;

        return sprintf(
            'The site %s the request for %s with status %d. %s',
            $refused ? 'refused' : 'answered',
            $request,
            $exception->status,
            $exception->getMessage(),
        );
    }

    /**
     * An OAuth refusal is named by its class and error code only: its
     * description is the provider's free text, which may echo what it was
     * sent, a secret included.
     */
    private static function failed(AppsolutelyException $exception): string
    {
        if ($exception instanceof OAuthException) {
            $error = preg_match('/^[\x20-\x21\x23-\x5B\x5D-\x7E]{1,64}$/', $exception->error) === 1 ? $exception->error : 'unreadable';

            return sprintf('The Appsolutely check failed: %s, OAuth error "%s".', $exception::class, $error);
        }

        return 'The Appsolutely check failed: ' . $exception->getMessage();
    }

    private function failure(string $reason): int
    {
        $this->newLine();
        $this->components->error($reason);

        return self::FAILURE;
    }

    /**
     * Warns when the status says deprecated or either date is announced.
     * The dates may lie in the past or the future, so they are given as
     * labels rather than in a tensed sentence, and only those the site
     * answered appear.
     */
    private static function deprecationWarning(ApiVersion $version): ?string
    {
        $deprecated = $version->status === self::STATUS_DEPRECATED;
        if (!$deprecated && $version->deprecatedAt === null && $version->sunsetAt === null) {
            return null;
        }

        $sentences = [match (true) {
            $deprecated => sprintf('API version %s is deprecated.', $version->version),
            $version->deprecatedAt !== null => sprintf('API version %s has a deprecation date.', $version->version),
            default => sprintf('API version %s has a sunset date.', $version->version),
        }];
        if ($version->deprecatedAt !== null) {
            $sentences[] = sprintf('Deprecation: %s.', self::time($version->deprecatedAt));
        }
        if ($version->sunsetAt !== null) {
            $sentences[] = sprintf('Sunset: %s.', self::time($version->sunsetAt));
        }
        $sentences[] = $version->successor === null
            ? 'Move to its successor once the site names one.'
            : sprintf('Move to %s.', $version->successor);

        return implode(' ', $sentences);
    }

    private static function time(?DateTimeInterface $time): string
    {
        return $time?->format(DATE_ATOM) ?? 'not stated';
    }
}

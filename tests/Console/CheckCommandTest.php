<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Console;

use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Appsolutely\Sdk\Laravel\Tests\Support\UnreachableStore;
use Appsolutely\Sdk\Laravel\Tests\Support\UnwritableStore;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;

final class CheckCommandTest extends TestCase
{
    private const string RIGHT_SECRET = 'b4ckend/s3cret';

    private const string WRONG_SECRET = 'b4ckend/wr0ng';

    public function testALegacyApiTokenWarnsWithoutFailingOrPrintingIt(): void
    {
        config()->set('appsolutely.api_token', self::RIGHT_SECRET);
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('The api_token / APPSOLUTELY_API_TOKEN setting is ignored; use the administrator credential instead.', $output);
        self::assertStringNotContainsString(self::RIGHT_SECRET, $output);
    }

    public function testAnUnsetLegacyApiTokenDoesNotWarn(): void
    {
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(0, $exitCode, $output);
        self::assertStringNotContainsString('APPSOLUTELY_API_TOKEN', $output);
        self::assertStringContainsString('The administrator client is not configured; administrator checks skipped.', $output);
    }

    /**
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function partialAdministratorCredentials(): iterable
    {
        yield 'id only' => ['admin-789', null, 'APPSOLUTELY_ADMIN_CLIENT_SECRET'];
        yield 'secret only' => [null, self::RIGHT_SECRET, 'APPSOLUTELY_ADMIN_CLIENT_ID'];
    }

    #[DataProvider('partialAdministratorCredentials')]
    public function testAPartialAdministratorCredentialNamesOnlyItsMissingVariable(?string $id, ?string $secret, string $missing): void
    {
        config()->set('appsolutely.admin.client_id', $id);
        config()->set('appsolutely.admin.client_secret', $secret);
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(1, $exitCode, $output);
        preg_match_all('/APPSOLUTELY_ADMIN_[A-Z_]+/', $output, $variables);
        self::assertSame([$missing], array_values(array_unique($variables[0])));
        self::assertStringNotContainsString(self::RIGHT_SECRET, $output);
    }

    public function testACompleteAdministratorCredentialIsReportedWithoutPrintingItsSecret(): void
    {
        config()->set('appsolutely.admin.client_id', 'admin-789');
        config()->set('appsolutely.admin.client_secret', self::RIGHT_SECRET);
        config()->set('appsolutely.admin.scopes', 'admin:pages:read');
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('The administrator credential is configured.', $output);
        self::assertStringNotContainsString(self::RIGHT_SECRET, $output);
    }

    public function testAnInvalidAdministratorScopeFailsBeforeReportingTheCredentialConfigured(): void
    {
        config()->set('appsolutely.admin.client_id', 'admin-789');
        config()->set('appsolutely.admin.client_secret', self::RIGHT_SECRET);
        config()->set('appsolutely.admin.scopes', 'pages:read');
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(1, $exitCode, $output);
        self::assertStringNotContainsString('The administrator credential is configured.', $output);
        self::assertStringNotContainsString(self::RIGHT_SECRET, $output);
    }

    public function testItPrintsTheSitesApiVersionAndTheProvidersEndpoints(): void
    {
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        // Each output line goes to the first expectation that matches it,
        // and the issuer is a prefix of every endpoint, so it comes last.
        $this->check()
            ->expectsOutputToContain(self::BASE_URL)
            ->expectsOutputToContain('v1')
            ->expectsOutputToContain('current')
            ->expectsOutputToContain(FakeOpenIdProvider::TOKEN_URL)
            ->expectsOutputToContain(FakeOpenIdProvider::JWKS_URL)
            ->expectsOutputToContain(FakeOpenIdProvider::USERINFO_URL)
            ->expectsOutputToContain(FakeOpenIdProvider::ISSUER)
            ->assertSuccessful();

        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET' && $request->url() === self::BASE_URL . '/api/v1');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusalStatuses(): iterable
    {
        yield 'unauthenticated' => [401];
        yield 'forbidden' => [403];
    }

    #[DataProvider('refusalStatuses')]
    public function testARefusedVersionReadIsTheSiteRefusingThisHost(int $status): void
    {
        // A proxy in front of the site refusing this host.
        Http::fake([self::BASE_URL . '/api/v1' => Http::response('<html>Access denied</html>', $status, ['Content-Type' => 'text/html'])]);

        $this->check()
            ->expectsOutputToContain(sprintf('The site refused the request for its API version (GET /api/v1) with status %d', $status))
            ->assertFailed();
    }

    public function testADeprecatedVersionIsAWarningWithItsSunsetAndSuccessor(): void
    {
        self::fakeSite([
            'version' => 'v1',
            'status' => 'deprecated',
            'deprecated_at' => '2026-09-01T00:00:00Z',
            'sunset_at' => '2027-03-01T00:00:00Z',
            'successor' => self::BASE_URL . '/api/v2',
        ]);
        (new FakeOpenIdProvider())->fake();

        $this->check()
            ->expectsOutputToContain('2027-03-01T00:00:00+00:00')
            ->expectsOutputToContain(self::BASE_URL . '/api/v2')
            ->expectsOutputToContain('deprecated')
            ->assertSuccessful();
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function deprecations(): iterable
    {
        yield 'status only' => [
            ['status' => 'deprecated'],
            'API version v1 is deprecated. Move to its successor once the site names one.',
        ];
        yield 'deprecation date only' => [
            ['status' => 'current', 'deprecated_at' => '2026-09-01T00:00:00Z'],
            'API version v1 has a deprecation date. Deprecation: 2026-09-01T00:00:00+00:00. Move to its successor once the site names one.',
        ];
        yield 'deprecation date still ahead' => [
            ['status' => 'current', 'deprecated_at' => '2099-01-01T00:00:00Z'],
            'API version v1 has a deprecation date. Deprecation: 2099-01-01T00:00:00+00:00. Move to its successor once the site names one.',
        ];
        yield 'sunset only' => [
            ['status' => 'current', 'sunset_at' => '2027-03-01T00:00:00Z'],
            'API version v1 has a sunset date. Sunset: 2027-03-01T00:00:00+00:00. Move to its successor once the site names one.',
        ];
        yield 'both dates without the status' => [
            ['status' => 'current', 'deprecated_at' => '2026-09-01T00:00:00Z', 'sunset_at' => '2027-03-01T00:00:00Z'],
            'API version v1 has a deprecation date. Deprecation: 2026-09-01T00:00:00+00:00. Sunset: 2027-03-01T00:00:00+00:00. Move to its successor once the site names one.',
        ];
        yield 'status and successor' => [
            ['status' => 'deprecated', 'successor' => self::BASE_URL . '/api/v2'],
            'API version v1 is deprecated. Move to ' . self::BASE_URL . '/api/v2.',
        ];
        yield 'every field' => [
            ['status' => 'deprecated', 'deprecated_at' => '2026-09-01T00:00:00Z', 'sunset_at' => '2027-03-01T00:00:00Z', 'successor' => self::BASE_URL . '/api/v2'],
            'API version v1 is deprecated. Deprecation: 2026-09-01T00:00:00+00:00. Sunset: 2027-03-01T00:00:00+00:00. Move to ' . self::BASE_URL . '/api/v2.',
        ];
    }

    /**
     * @param array<string, string> $lifecycle
     */
    #[DataProvider('deprecations')]
    public function testTheDeprecationWarningStatesOnlyWhatTheSiteAnswered(array $lifecycle, string $warning): void
    {
        self::fakeSite(['version' => 'v1', ...$lifecycle]);
        (new FakeOpenIdProvider())->fake();

        $this->check()
            ->expectsOutputToContain($warning)
            ->doesntExpectOutputToContain(': not stated')
            ->assertSuccessful();
    }

    public function testACurrentVersionWithoutDatesIsNoWarning(): void
    {
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();

        $this->check()
            ->doesntExpectOutputToContain('API version v1 ')
            ->doesntExpectOutputToContain('Sunset:')
            ->assertSuccessful();
    }

    public function testAServerErrorFromTheSiteFailsWithoutCallingItARefusal(): void
    {
        Sleep::fake();
        Http::fake([self::BASE_URL . '/api/v1' => Http::response([
            'type' => 'https://appsolutely.io/problems/service-unavailable',
            'title' => 'Under maintenance',
            'status' => 503,
        ], 503, ['Content-Type' => 'application/problem+json'])]);
        (new FakeOpenIdProvider())->fake();

        $this->check()
            ->expectsOutputToContain('The site answered the request for its API version (GET /api/v1) with status 503.')
            ->doesntExpectOutputToContain('refused')
            ->assertFailed();
    }

    public function testAnIssuerWithoutDiscoveryFails(): void
    {
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        Http::fake([FakeOpenIdProvider::DISCOVERY_URL => Http::response('', 404)]);

        $this->check()
            ->expectsOutputToContain('v1')
            ->assertFailed();
    }

    public function testAnUnreachableProviderFails(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->check()
            ->expectsOutputToContain('Connection refused')
            ->assertFailed();
    }

    public function testAStrayRequestUnderATestFailsWithAMessage(): void
    {
        Http::preventStrayRequests();

        $this->check()
            ->expectsOutputToContain('without a matching fake')
            ->assertFailed();
    }

    public function testWithoutMemberSignInTheProviderChecksAreSkipped(): void
    {
        config()->set('appsolutely.issuer', null);
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        self::fakeSite(['version' => 'v1', 'status' => 'current']);

        $this->check()
            ->expectsOutputToContain('v1')
            ->expectsOutputToContain('Member sign-in is not configured; provider checks skipped.')
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function testABackendClientIsProvenByFetchingItsToken(): void
    {
        self::withBackendOnly();
        self::fakeSiteWithBackend(FakeOpenIdProvider::ISSUER);

        $this->check()
            // One table row, so one expectation: each line meets only the first that matches it.
            ->expectsOutputToContain('Backend client | accepted as backend-456')
            ->assertSuccessful();

        self::assertCount(1, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
    }

    public function testABackendClientBehindAWrongIssuerFailsTheCheck(): void
    {
        self::withBackendOnly();
        config()->set('appsolutely.issuer', 'https://wrong.example.com');
        self::fakeSiteWithBackend(FakeOpenIdProvider::ISSUER);

        $this->check()
            ->expectsOutputToContain('The Appsolutely check failed')
            ->assertFailed();

        self::assertCount(0, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
    }

    public function testARefusedBackendClientFailsTheCheck(): void
    {
        self::withBackendOnly();
        $provider = new FakeOpenIdProvider();
        Http::fake([
            self::BASE_URL . '/api/v1' => Http::response(['version' => 'v1', 'status' => 'current']),
            FakeOpenIdProvider::DISCOVERY_URL => Http::response($provider->discovery),
            FakeOpenIdProvider::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->check()
            ->expectsOutputToContain('Backend client | not proven')
            ->assertFailed();
    }

    public function testACachedBackendTokenDoesNotProveARotatedSecret(): void
    {
        self::withBackendOnly();
        self::fakeIssuerCheckingTheSecret();
        // A token granted to the right secret, kept in the shared store.
        Appsolutely::backend()->client();

        config()->set('appsolutely.backend.client_secret', self::WRONG_SECRET);
        $this->app?->forgetInstance(Client::class);
        Appsolutely::clearResolvedInstances();

        $this->check()->assertFailed();
    }

    public function testTheChecksTokenDoesNotReplaceTheSharedOne(): void
    {
        self::withBackendOnly();
        self::fakeIssuerCheckingTheSecret();
        Appsolutely::backend()->client();

        $this->check()->assertSuccessful();
        self::assertCount(2, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL), 'The check did not fetch a token of its own.');

        Appsolutely::backend()->client();
        self::assertCount(2, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
        $reads = Http::recorded(static fn(Request $request): bool => $request->url() === self::BASE_URL . '/api/v1/client');
        $last = $reads->last();
        self::assertIsArray($last);
        self::assertSame(['Bearer machine-token-1'], $last[0]->header('Authorization'));
    }

    public function testABackendClientGrantedFewerScopesThanConfiguredFails(): void
    {
        self::withBackendOnly();
        config()->set('appsolutely.backend.scopes', 'seats:read roles:read roles:write');
        self::fakeIssuerCheckingTheSecret(granted: ['seats:read']);

        $this->check()
            ->expectsOutputToContain('roles:read, roles:write')
            ->assertFailed();
    }

    /**
     * An OAuth refusal is named by its class and error code: the provider's
     * description is free text that may echo what it was sent. Read from the
     * whole output, verbose, rather than line by line, where a line already
     * matched by one expectation escapes the others.
     */
    public function testARefusedBackendClientIsReportedWithoutTheProvidersDescription(): void
    {
        self::withBackendOnly();
        config()->set('appsolutely.backend.client_secret', self::WRONG_SECRET);
        self::fakeIssuerCheckingTheSecret();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('OAuthException', $output);
        self::assertStringContainsString('invalid_client', $output);
        self::assertStringNotContainsString(self::WRONG_SECRET, $output);
        self::assertStringNotContainsString('does not match', $output);
    }

    public function testAProvenBackendClientNeverPrintsItsSecretOrToken(): void
    {
        self::withBackendOnly();
        self::fakeIssuerCheckingTheSecret();

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('accepted as backend-456', $output);
        self::assertStringNotContainsString(self::RIGHT_SECRET, $output);
        self::assertStringNotContainsString('machine-token', $output);
    }

    public function testTheCacheStoreIsProvenWithAThrowawayKey(): void
    {
        self::fakeSite(['version' => 'v1', 'status' => 'current']);
        (new FakeOpenIdProvider())->fake();
        $written = [];
        Event::listen(KeyWritten::class, static function (KeyWritten $event) use (&$written): void {
            $written[] = $event->key;
        });

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Cache store', $output);
        self::assertStringContainsString('written, read and deleted', $output);
        $probes = array_values(array_filter($written, static fn(string $key): bool => str_starts_with($key, 'appsolutely.check.')));
        self::assertCount(1, $probes);
        self::assertFalse(Cache::has($probes[0]), 'The throwaway key was left behind.');
    }

    /**
     * @return iterable<string, array{class-string<Store>}>
     */
    public static function brokenStores(): iterable
    {
        yield 'unreachable' => [UnreachableStore::class];
        yield 'unwritable' => [UnwritableStore::class];
    }

    /**
     * @param class-string<Store> $store
     */
    #[DataProvider('brokenStores')]
    public function testABrokenCacheStoreFailsTheCheckWithMemberSignInOff(string $store): void
    {
        Cache::extend('broken', fn(): Repository => Cache::repository(new $store()));
        config()->set('cache.stores.broken', ['driver' => 'broken']);
        config()->set('appsolutely.cache_store', 'broken');
        config()->set('appsolutely.issuer', null);
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        self::fakeSite(['version' => 'v1', 'status' => 'current']);

        [$exitCode, $output] = $this->checkOutput();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Cache store', $output);
        self::assertStringContainsString('APPSOLUTELY_CACHE_STORE', $output);
        self::assertStringNotContainsString('cache.internal', $output);
    }

    public function testAMissingSettingFailsNamingWhatToSet(): void
    {
        config()->set('appsolutely.client_secret', null);

        $this->check()
            ->expectsOutputToContain('APPSOLUTELY_CLIENT_SECRET')
            ->assertFailed();
    }

    public function testAMissingBaseUrlFailsNamingWhatToSetBeforeAnyRequest(): void
    {
        config()->set('appsolutely.base_url', null);
        Http::preventStrayRequests();

        $this->check()
            ->expectsOutputToContain('The Appsolutely setting appsolutely.base_url is not set; set APPSOLUTELY_BASE_URL')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $version
     */
    private static function fakeSite(array $version): void
    {
        Http::fake([self::BASE_URL . '/api/v1' => Http::response($version)]);
    }

    /**
     * The issuer grants a token only to the right secret, numbering each, and
     * echoes the secret it refused in its description, as a careless one may.
     *
     * @param list<string> $granted the scopes the site reports for the client
     */
    private static function fakeIssuerCheckingTheSecret(array $granted = ['seats:read']): void
    {
        $provider = new FakeOpenIdProvider();
        $issued = 0;
        Http::fake(static function (Request $request) use ($provider, $granted, &$issued): PromiseInterface {
            $url = $request->url();
            $right = 'Basic ' . base64_encode('backend-456:' . urlencode(self::RIGHT_SECRET));

            return match (true) {
                $url === self::BASE_URL . '/api/v1' => Http::response(['version' => 'v1', 'status' => 'current']),
                $url === FakeOpenIdProvider::DISCOVERY_URL => Http::response($provider->discovery),
                $url === FakeOpenIdProvider::TOKEN_URL && $request->header('Authorization') === [$right] => Http::response(['access_token' => 'machine-token-' . ++$issued, 'token_type' => 'Bearer', 'expires_in' => 3600]),
                $url === FakeOpenIdProvider::TOKEN_URL => Http::response(['error' => 'invalid_client', 'error_description' => 'The secret ' . self::WRONG_SECRET . ' does not match.'], 401),
                $url === self::BASE_URL . '/api/v1/client' => Http::response(['client_id' => 'backend-456', 'mode' => 'production', 'site' => 'shop', 'store_currency' => 'USD', 'scopes' => $granted]),
                default => Http::response('', 404),
            };
        });
    }

    private static function withBackendOnly(): void
    {
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', self::RIGHT_SECRET);
    }

    /** The site, its issuer at $issuer, and the backend's token and client read; anything else 404. */
    private static function fakeSiteWithBackend(string $issuer): void
    {
        $provider = new FakeOpenIdProvider();
        Http::fake([
            self::BASE_URL . '/api/v1' => Http::response(['version' => 'v1', 'status' => 'current']),
            $issuer . '/.well-known/openid-configuration' => Http::response($provider->discovery),
            FakeOpenIdProvider::TOKEN_URL => Http::response(['access_token' => 'machine-token-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::BASE_URL . '/api/v1/client' => Http::response(['client_id' => 'backend-456', 'mode' => 'production', 'site' => 'shop', 'store_currency' => 'USD', 'scopes' => ['seats:read']]),
            '*' => Http::response('', 404),
        ]);
    }

    /**
     * @return array{int, string} the exit code and the whole output, verbose
     */
    private function checkOutput(): array
    {
        $exitCode = Artisan::call('appsolutely:check', ['-v' => true]);

        return [$exitCode, Artisan::output()];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function check(array $parameters = []): PendingCommand
    {
        $command = $this->artisan('appsolutely:check', $parameters);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}

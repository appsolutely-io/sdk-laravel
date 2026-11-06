<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests;

use Appsolutely\Sdk\Admin\AdminClient;
use Appsolutely\Sdk\Api\PublicApi;
use Appsolutely\Sdk\Cache\TokenLock;
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Exception\AppsolutelyException;
use Appsolutely\Sdk\Exception\IdTokenException;
use Appsolutely\Sdk\Exception\InvalidConfigException;
use Appsolutely\Sdk\Exception\TransportException;
use Appsolutely\Sdk\Laravel\AppsolutelyServiceProvider;
use Appsolutely\Sdk\Laravel\ClientFactory;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;
use Appsolutely\Sdk\Laravel\Settings;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Appsolutely\Sdk\Laravel\Tests\Support\SigningKey;
use Appsolutely\Sdk\Laravel\Webhooks\DeliveryLock;
use Appsolutely\Sdk\Laravel\Webhooks\WebhookRoute;
use Appsolutely\Sdk\Webhooks\Deliveries;
use Appsolutely\Sdk\Webhooks\Verifier;
use Carbon\CarbonInterval;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServiceProviderTest extends TestCase
{
    /** The version record anyone may read, the smallest call through the client. */
    private const array VERSION = ['version' => 'v1', 'status' => 'current'];

    public function testTheClientIsOneSharedInstanceBehindTheFacade(): void
    {
        $client = $this->app?->make(Client::class);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame($client, $this->app?->make(Client::class));
        self::assertSame($client, Appsolutely::getFacadeRoot());
    }

    public function testThePublicApiCanBeInjected(): void
    {
        $api = $this->app?->make(PublicApi::class);

        self::assertInstanceOf(PublicApi::class, $api);
        self::assertSame(Appsolutely::api(), $api);
    }

    public function testAMissingSettingSurfacesWhenTheClientIsFirstResolvedNotAtBoot(): void
    {
        // The application booted in setUp() with the provider registered;
        // only resolving the client reads the settings.
        config()->set('appsolutely.issuer', null);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_ISSUER');

        $this->app?->make(Client::class);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'missing base URL' => ['appsolutely.base_url', null, 'APPSOLUTELY_BASE_URL'];
        yield 'empty client id' => ['appsolutely.client_id', '', 'APPSOLUTELY_CLIENT_ID'];
        yield 'missing client secret' => ['appsolutely.client_secret', null, 'APPSOLUTELY_CLIENT_SECRET'];
        yield 'unknown client authentication' => ['appsolutely.client_authentication', 'private_key_jwt', 'APPSOLUTELY_CLIENT_AUTHENTICATION'];
        yield 'clock leeway that is not a number' => ['appsolutely.clock_leeway', 'a minute', 'APPSOLUTELY_CLOCK_LEEWAY'];
        yield 'cache store that is not a name' => ['appsolutely.cache_store', ['redis'], 'APPSOLUTELY_CACHE_STORE'];
    }

    #[DataProvider('invalidSettings')]
    public function testItRefusesAnInvalidSettingNamingItsEnvironmentVariable(string $key, mixed $value, string $variable): void
    {
        config()->set($key, $value);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($variable);

        $this->app?->make(Client::class);
    }

    public function testAnApplicationThatNeverSignsMembersInNeedsNoSignInSettings(): void
    {
        config()->set('appsolutely.issuer', null);
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        Http::fake([self::BASE_URL . '/api/v1' => Http::response(self::VERSION)]);

        self::assertSame('v1', Appsolutely::api()->version()->version);
    }

    public function testTheBackendNeedsNoMemberSignInClient(): void
    {
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        self::fakeBackend();

        self::assertSame('backend-456', Appsolutely::backend()->client()->clientId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signInClientHalves(): iterable
    {
        yield 'id without secret' => ['appsolutely.client_secret'];
        yield 'secret without id' => ['appsolutely.client_id'];
    }

    #[DataProvider('signInClientHalves')]
    public function testHalfASignInClientIsRefusedNamingBothVariables(string $unset): void
    {
        config()->set($unset, null);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/(?=.*APPSOLUTELY_CLIENT_ID\b)(?=.*APPSOLUTELY_CLIENT_SECRET\b)/s');

        $this->app?->make(Client::class);
    }

    public function testTheBackendWithoutAnIssuerIsRefusedNamingIt(): void
    {
        config()->set('appsolutely.issuer', null);
        config()->set('appsolutely.client_id', null);
        config()->set('appsolutely.client_secret', null);
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_ISSUER');

        $this->app?->make(Client::class);
    }

    public function testItAcceptsTheClockLeewayAsTheStringAnEnvironmentVariableHolds(): void
    {
        config()->set('appsolutely.clock_leeway', '30');

        self::assertInstanceOf(Client::class, $this->app?->make(Client::class));
    }

    public function testTheSdkSendsItsRequestsThroughLaravelsHttpClient(): void
    {
        (new FakeOpenIdProvider())->fake();

        $metadata = Appsolutely::oidc()->metadata();

        self::assertSame(FakeOpenIdProvider::TOKEN_URL, $metadata->tokenEndpoint);
        Http::assertSent(static function (Request $request): bool {
            $userAgent = $request->header('User-Agent')[0] ?? null;

            return $request->method() === 'GET'
                && $request->url() === FakeOpenIdProvider::DISCOVERY_URL
                && is_string($userAgent) && str_starts_with($userAgent, 'appsolutely-sdk-php/');
        });
        Http::assertSentCount(1);
    }

    public function testAFakeRegisteredAfterTheClientWasBuiltStillApplies(): void
    {
        $this->app?->make(Client::class);
        (new FakeOpenIdProvider())->fake();

        Appsolutely::oidc()->metadata();

        Http::assertSent(static fn(Request $request): bool => $request->url() === FakeOpenIdProvider::DISCOVERY_URL);
    }

    public function testPreventingStrayRequestsCoversTheSdkAndReachesTheCallerAsItsTransportException(): void
    {
        Http::preventStrayRequests();

        try {
            Appsolutely::oidc()->metadata();
            self::fail('A stray request was sent.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('without a matching fake', $exception->getMessage());
            self::assertInstanceOf(StrayRequestException::class, $exception->getPrevious()?->getPrevious());
        }

        Http::assertNothingSent();
    }

    public function testItAuthenticatesWithClientSecretPostWhenConfigured(): void
    {
        config()->set('appsolutely.client_authentication', 'client_secret_post');
        (new FakeOpenIdProvider())->fake();

        Appsolutely::oidc()->revoke('refresh-token-1');

        $request = FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::REVOCATION_URL)[0];
        self::assertTrue($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        self::assertSame(FakeOpenIdProvider::CLIENT_SECRET, FakeOpenIdProvider::form($request)['client_secret'] ?? null);
    }

    public function testARefusedConnectionReachesTheCallerAsTheSdksTransportException(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Connection refused');

        Appsolutely::oidc()->metadata();
    }

    public function testRedirectsAreHandedToTheSdkRatherThanFollowed(): void
    {
        Http::fake([
            FakeOpenIdProvider::DISCOVERY_URL => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/openid-configuration']),
            '*' => Http::response(['issuer' => 'https://elsewhere.example.com']),
        ]);

        try {
            Appsolutely::oidc()->metadata();
            self::fail('A redirected discovery request was accepted.');
        } catch (AppsolutelyException) {
        }

        Http::assertSentCount(1);
    }

    public function testTheDiscoveryDocumentIsCachedInTheConfiguredStore(): void
    {
        config()->set('cache.stores.appsolutely', ['driver' => 'array']);
        config()->set('appsolutely.cache_store', 'appsolutely');
        (new FakeOpenIdProvider())->fake();

        Appsolutely::oidc()->metadata();
        Cache::store('array')->clear();
        Appsolutely::oidc()->metadata();
        self::assertCount(1, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::DISCOVERY_URL));

        Cache::store('appsolutely')->clear();
        Appsolutely::oidc()->metadata();
        self::assertCount(2, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::DISCOVERY_URL));
    }

    public function testTheSdkReadsTheTimeFromLaravelsClock(): void
    {
        $provider = (new FakeOpenIdProvider())->fake();
        $this->freezeSecond();
        $idToken = $provider->rsa->sign($provider->claims(['exp' => now()->getTimestamp() + 300]));

        // Expired five minutes in, still accepted within the 60 seconds of leeway.
        $this->travel(359)->seconds();
        Appsolutely::oidc()->verifyIdToken($idToken, null);

        $this->travel(1)->seconds();
        $this->expectException(IdTokenException::class);
        Appsolutely::oidc()->verifyIdToken($idToken, null);
    }

    public function testTheSdkLogsThroughLaravelsLogger(): void
    {
        $provider = (new FakeOpenIdProvider())->fake();
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $this->freezeSecond();
        Appsolutely::oidc()->verifyIdToken($provider->rsa->sign($provider->claims()), null);
        $this->travel(61)->seconds();

        try {
            Appsolutely::oidc()->verifyIdToken(SigningKey::rsa('unpublished')->sign($provider->claims()), null);
            self::fail('An ID token signed with an unpublished key was accepted.');
        } catch (IdTokenException) {
        }

        self::assertContains('An ID token names an unknown signing key; fetching the key set again.', $logged);
    }

    public function testThePublicCallsGoToTheSiteWithoutACredential(): void
    {
        Http::fake([self::BASE_URL . '/api/v1' => Http::response(self::VERSION)]);

        Appsolutely::api()->version();

        Http::assertSent(static fn(Request $request): bool => $request->url() === self::BASE_URL . '/api/v1'
            && !$request->hasHeader('Authorization'));
    }

    public function testBackendCallsCarryTheTokenTheConfiguredMachineClientIsGranted(): void
    {
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        self::fakeBackend();

        self::assertSame('backend-456', Appsolutely::backend()->client()->clientId);

        $token = FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0];
        self::assertSame(['grant_type' => 'client_credentials'], FakeOpenIdProvider::form($token));
        self::assertSame(['Basic ' . base64_encode('backend-456:' . urlencode('b4ckend/s3cret'))], $token->header('Authorization'));
        Http::assertSent(static fn(Request $request): bool => $request->url() === self::BASE_URL . '/api/v1/client'
            && $request->header('Authorization') === ['Bearer machine-token-1']);
    }

    public function testTheBackendsScopesAreASpaceSeparatedList(): void
    {
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        config()->set('appsolutely.backend.scopes', 'seats:read  roles:read');
        self::fakeBackend();

        Appsolutely::backend()->client();

        self::assertSame('seats:read roles:read', FakeOpenIdProvider::form(FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0])['scope'] ?? null);
    }

    public function testTheBackendAuthenticatesAsItsOwnSettingSays(): void
    {
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        config()->set('appsolutely.backend.client_authentication', 'client_secret_post');
        self::fakeBackend();

        Appsolutely::backend()->client();

        $token = FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0];
        self::assertSame('b4ckend/s3cret', FakeOpenIdProvider::form($token)['client_secret'] ?? null);
        self::assertFalse($token->hasHeader('Authorization'));
    }

    public function testWithoutItsOwnSettingTheBackendAuthenticatesAsTheSignInClientDoes(): void
    {
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        config()->set('appsolutely.client_authentication', 'client_secret_post');
        self::fakeBackend();

        Appsolutely::backend()->client();

        self::assertSame('b4ckend/s3cret', FakeOpenIdProvider::form(FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0])['client_secret'] ?? null);
    }

    public function testAnUnknownBackendClientAuthenticationIsRefusedNamingItsVariable(): void
    {
        config()->set('appsolutely.backend.client_authentication', 'private_key_jwt');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_BACKEND_CLIENT_AUTHENTICATION');

        $this->app?->make(Client::class);
    }

    public function testTheBackendsTokenIsFetchedUnderTheContainersTokenLock(): void
    {
        config()->set('appsolutely.backend.client_id', 'backend-456');
        config()->set('appsolutely.backend.client_secret', 'b4ckend/s3cret');
        $lock = new class implements TokenLock {
            /** @var list<string> */
            public array $names = [];

            public function run(string $name, int $seconds, callable $fetch): mixed
            {
                $this->names[] = $name;

                return $fetch();
            }

            public function runIfFree(string $name, int $seconds, callable $fetch): string
            {
                $this->names[] = $name;

                return $fetch();
            }
        };
        $this->app?->instance(TokenLock::class, $lock);
        self::fakeBackend();

        Appsolutely::backend()->client();

        self::assertCount(1, $lock->names);
        self::assertCount(1, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
    }

    public function testWithoutABackendClientTheBackendIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);

        Appsolutely::backend();
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function administratorAuthentications(): iterable
    {
        yield 'default basic' => ['client_secret_basic', null, false];
        yield 'fallback post' => ['client_secret_post', null, true];
        yield 'override post' => ['client_secret_basic', 'client_secret_post', true];
        yield 'override basic' => ['client_secret_post', 'client_secret_basic', false];
    }

    #[DataProvider('administratorAuthentications')]
    public function testTheAdministratorApiIsCalledWithTheConfiguredCredential(string $general, ?string $override, bool $post): void
    {
        config()->set('appsolutely.client_authentication', $general);
        config()->set('appsolutely.admin.client_authentication', $override);
        config()->set('appsolutely.admin.client_id', 'admin-789');
        config()->set('appsolutely.admin.client_secret', 'adm1n/s3cret');
        config()->set('appsolutely.admin.scopes', 'admin:pages:read  admin:articles:read');
        $provider = new FakeOpenIdProvider();
        Http::fake([
            FakeOpenIdProvider::DISCOVERY_URL => Http::response($provider->discovery),
            FakeOpenIdProvider::TOKEN_URL => Http::response(['access_token' => 'admin-token-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::BASE_URL . '/api/admin/v1/pages*' => Http::response(['data' => []]),
        ]);

        $admin = $this->app?->make(AdminClient::class);
        self::assertInstanceOf(AdminClient::class, $admin);
        self::assertSame($admin, $this->app?->make(AdminClient::class));
        self::assertSame([], iterator_to_array($admin->pages()->list()));

        $token = FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0];
        $body = ['grant_type' => 'client_credentials', 'scope' => 'admin:pages:read admin:articles:read'];
        if ($post) {
            $body += ['client_id' => 'admin-789', 'client_secret' => 'adm1n/s3cret'];
        }
        self::assertSame($body, FakeOpenIdProvider::form($token));
        self::assertSame($post ? [] : ['Basic ' . base64_encode('admin-789:' . urlencode('adm1n/s3cret'))], $token->header('Authorization'));
        Http::assertSent(static fn(Request $request): bool => str_starts_with($request->url(), self::BASE_URL . '/api/admin/v1/pages')
            && $request->header('Authorization') === ['Bearer admin-token-1']);
    }

    public function testWithoutAnAdministratorCredentialTheAdminClientIsRefusedNamingWhatToSet(): void
    {
        config()->set('appsolutely.admin.client_id', 'admin-789');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/(?=.*APPSOLUTELY_ADMIN_CLIENT_SECRET\b)(?=.*APPSOLUTELY_ADMIN_SCOPES\b)(?!.*APPSOLUTELY_ADMIN_CLIENT_ID\b)/s');

        $this->app?->make(AdminClient::class);
    }

    public function testTheSiteClientNeedsNoAdministratorCredential(): void
    {
        config()->set('appsolutely.admin', []);
        config()->set('appsolutely.webhooks.secrets', 'whsec_' . base64_encode(str_repeat('k', 32)));
        foreach ([
            Client::class,
            PublicApi::class,
            Settings::class,
            ClientFactory::class,
            TokenLock::class,
            Verifier::class,
            DeliveryLock::class,
            Deliveries::class,
            WebhookRoute::class,
        ] as $binding) {
            self::assertInstanceOf($binding, $this->app?->make($binding));
        }

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/(?=.*APPSOLUTELY_ADMIN_CLIENT_ID\\b)(?=.*APPSOLUTELY_ADMIN_CLIENT_SECRET\\b)(?=.*APPSOLUTELY_ADMIN_SCOPES\\b)/s');
        $this->app?->make(AdminClient::class);
    }

    public function testAnUnknownAdministratorAuthenticationNamesItsVariable(): void
    {
        config()->set('appsolutely.admin.client_id', 'admin-789');
        config()->set('appsolutely.admin.client_secret', 'adm1n/s3cret');
        config()->set('appsolutely.admin.scopes', 'admin:pages:read');
        config()->set('appsolutely.admin.client_authentication', 'private_key_jwt');

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_ADMIN_CLIENT_AUTHENTICATION');
        $this->app?->make(AdminClient::class);
    }

    public function testRetriesWaitThroughLaravelsSleep(): void
    {
        Sleep::fake();
        Http::fake([self::BASE_URL . '/api/v1' => Http::sequence()
            ->push('', 503, ['Retry-After' => '2'])
            ->push(self::VERSION)]);

        Appsolutely::api()->version();

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        Http::assertSentCount(2);
    }

    public function testTheConfiguredTimeoutsApplyToTheSdksRequests(): void
    {
        config()->set('appsolutely.http.timeout', '12.5');
        config()->set('appsolutely.http.connect_timeout', '3');
        $options = [];
        Http::fake(static function (Request $request, array $sent) use (&$options): PromiseInterface {
            $options = $sent;

            return Http::response(self::VERSION);
        });

        Appsolutely::api()->version();

        self::assertSame(12.5, $options['timeout'] ?? null);
        self::assertSame(3.0, $options['connect_timeout'] ?? null);
    }

    public function testWithoutTimeoutSettingsTheSdksDefaultsApply(): void
    {
        $options = [];
        Http::fake(static function (Request $request, array $sent) use (&$options): PromiseInterface {
            $options = $sent;

            return Http::response(self::VERSION);
        });

        Appsolutely::api()->version();

        self::assertSame(Config::DEFAULT_TIMEOUT, $options['timeout'] ?? null);
        self::assertSame(Config::DEFAULT_CONNECT_TIMEOUT, $options['connect_timeout'] ?? null);
    }

    public function testNoRetriesAreMadeWhenTheMaximumIsZero(): void
    {
        config()->set('appsolutely.http.retries.max', '0');
        Sleep::fake();
        Http::fake([self::BASE_URL . '/api/v1' => Http::response('', 503)]);

        try {
            Appsolutely::api()->version();
            self::fail('A refused read was answered.');
        } catch (AppsolutelyException) {
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function testTheBackoffStartsFromTheConfiguredBaseDelay(): void
    {
        config()->set('appsolutely.http.retries.base_delay', '4');
        Sleep::fake();
        Http::fake([self::BASE_URL . '/api/v1' => Http::sequence()->push('', 503)->push(self::VERSION)]);

        Appsolutely::api()->version();

        // Jittered between half and all of the first backoff.
        Sleep::assertSlept(static fn(CarbonInterval $wait): bool => $wait->totalSeconds >= 2 && $wait->totalSeconds <= 4);
    }

    public function testAWaitBeyondTheConfiguredLongestIsNotMade(): void
    {
        config()->set('appsolutely.http.retries.max_delay', '5');
        Sleep::fake();
        Http::fake([self::BASE_URL . '/api/v1' => Http::response('', 503, ['Retry-After' => '10'])]);

        try {
            Appsolutely::api()->version();
            self::fail('A refused read was answered.');
        } catch (AppsolutelyException) {
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidHttpSettings(): iterable
    {
        yield 'timeout that is not a number' => ['appsolutely.http.timeout', 'thirty', 'APPSOLUTELY_HTTP_TIMEOUT'];
        yield 'connect timeout that is not a number' => ['appsolutely.http.connect_timeout', '-1', 'APPSOLUTELY_HTTP_CONNECT_TIMEOUT'];
        yield 'retries that are not a whole number' => ['appsolutely.http.retries.max', '1.5', 'APPSOLUTELY_HTTP_RETRIES'];
        yield 'retries beyond the SDK\'s limit' => ['appsolutely.http.retries.max', '11', 'APPSOLUTELY_HTTP_RETRIES'];
        yield 'base delay that is not a number' => ['appsolutely.http.retries.base_delay', 'soon', 'APPSOLUTELY_HTTP_RETRY_BASE_DELAY'];
        yield 'longest wait beyond the SDK\'s limit' => ['appsolutely.http.retries.max_delay', '301', 'APPSOLUTELY_HTTP_RETRY_MAX_DELAY'];
        yield 'timeout of zero' => ['appsolutely.http.timeout', '0', 'APPSOLUTELY_HTTP_TIMEOUT'];
        yield 'timeout beyond the SDK\'s limit' => ['appsolutely.http.timeout', '601', 'APPSOLUTELY_HTTP_TIMEOUT'];
        yield 'connect timeout of zero' => ['appsolutely.http.connect_timeout', '0', 'APPSOLUTELY_HTTP_CONNECT_TIMEOUT'];
        yield 'connect timeout beyond the request timeout' => ['appsolutely.http.connect_timeout', '31', 'APPSOLUTELY_HTTP_CONNECT_TIMEOUT'];
        yield 'clock leeway beyond the SDK\'s limit' => ['appsolutely.clock_leeway', '301', 'APPSOLUTELY_CLOCK_LEEWAY'];
        yield 'negative clock leeway' => ['appsolutely.clock_leeway', -1, 'APPSOLUTELY_CLOCK_LEEWAY'];
    }

    #[DataProvider('invalidHttpSettings')]
    public function testItRefusesAnInvalidHttpSettingNamingItsEnvironmentVariable(string $key, mixed $value, string $variable): void
    {
        config()->set($key, $value);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($variable);

        $this->app?->make(Client::class);
    }

    /**
     * @return iterable<string, array{string, int|string, string}>
     */
    public static function outOfRangeRetrySettings(): iterable
    {
        yield 'retries beyond the SDK\'s limit' => ['appsolutely.http.retries.max', '11', 'APPSOLUTELY_HTTP_RETRIES'];
        // Written in config/appsolutely.php rather than the environment, as an integer.
        yield 'negative retries' => ['appsolutely.http.retries.max', -1, 'APPSOLUTELY_HTTP_RETRIES'];
        yield 'first backoff of zero' => ['appsolutely.http.retries.base_delay', '0', 'APPSOLUTELY_HTTP_RETRY_BASE_DELAY'];
        yield 'first backoff beyond the longest wait' => ['appsolutely.http.retries.base_delay', '31', 'APPSOLUTELY_HTTP_RETRY_BASE_DELAY'];
        yield 'longest wait of zero' => ['appsolutely.http.retries.max_delay', '0', 'APPSOLUTELY_HTTP_RETRY_MAX_DELAY'];
        yield 'longest wait beyond the SDK\'s limit' => ['appsolutely.http.retries.max_delay', '301', 'APPSOLUTELY_HTTP_RETRY_MAX_DELAY'];
    }

    #[DataProvider('outOfRangeRetrySettings')]
    public function testAnOutOfRangeRetrySettingNamesOnlyItsOwnVariable(string $key, int|string $value, string $variable): void
    {
        config()->set($key, $value);
        $others = array_diff(['APPSOLUTELY_HTTP_RETRIES', 'APPSOLUTELY_HTTP_RETRY_BASE_DELAY', 'APPSOLUTELY_HTTP_RETRY_MAX_DELAY'], [$variable]);

        // Its own variable named, and neither of the others anywhere.
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches(sprintf('/^(?!.*\b(?:%s)\b).*\b%s\b/s', implode('|', $others), $variable));

        $this->app?->make(Client::class);
    }

    public function testANegativeWebhookToleranceIsRefusedNamingItsVariable(): void
    {
        config()->set('appsolutely.webhooks.secrets', 'whsec_' . base64_encode(str_repeat('s', 32)));
        // Written in config/appsolutely.php rather than the environment, as an integer.
        config()->set('appsolutely.webhooks.tolerance', -1);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_WEBHOOK_TOLERANCE');

        $this->app?->make(Verifier::class);
    }

    private static function fakeBackend(): void
    {
        $provider = new FakeOpenIdProvider();
        Http::fake([
            FakeOpenIdProvider::DISCOVERY_URL => Http::response($provider->discovery),
            FakeOpenIdProvider::TOKEN_URL => Http::response(['access_token' => 'machine-token-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::BASE_URL . '/api/v1/client' => Http::response(['client_id' => 'backend-456', 'mode' => 'production', 'site' => 'shop', 'store_currency' => 'USD', 'scopes' => ['seats:read']]),
        ]);
    }

    public function testTheConfigurationIsPublishedUnderItsOwnTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(AppsolutelyServiceProvider::class, 'appsolutely-config');

        self::assertSame([realpath(__DIR__ . '/../config/appsolutely.php')], array_map(realpath(...), array_keys($paths)));
        self::assertSame([$this->app?->configPath('appsolutely.php')], array_values($paths));
    }
}

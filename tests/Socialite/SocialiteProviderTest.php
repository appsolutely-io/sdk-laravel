<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Socialite;

use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Exception\AuthorizationResponseException;
use Appsolutely\Sdk\Exception\IdTokenException;
use Appsolutely\Sdk\Exception\InvalidArgumentValueException;
use Appsolutely\Sdk\Exception\InvalidConfigException;
use Appsolutely\Sdk\Exception\OAuthException;
use Appsolutely\Sdk\Laravel\Socialite\AppsolutelyProvider;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\InvalidStateException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SocialiteProviderTest extends TestCase
{
    private const string CALLBACK = 'http://localhost/auth/appsolutely/callback';

    /** Where the driver keeps the sign-in in progress; its own detail, pinned here to look inside. */
    private const string PENDING_SIGN_IN = 'appsolutely.sign_in';

    private FakeOpenIdProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('appsolutely.socialite.redirect', '/auth/appsolutely/callback');
        $this->provider = (new FakeOpenIdProvider())->fake();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SocialiteServiceProvider::class];
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->group(['middleware' => 'web'], function (Registrar $router): void {
            $router->get('/auth/appsolutely', fn(): RedirectResponse => $this->driver()->redirect());
            $router->get('/auth/appsolutely/recent', fn(): RedirectResponse => $this->driver()->maxAge(300)->redirect());
            $router->get('/auth/appsolutely/callback', function (): JsonResponse {
                $user = $this->driver()->user();

                return new JsonResponse([
                    'id' => $user->getId(),
                    'name' => $user->getName(),
                    'nickname' => $user->getNickname(),
                    'email' => $user->getEmail(),
                    'avatar' => $user->getAvatar(),
                    'token' => $user->token,
                    'refresh_token' => $user->refreshToken,
                    'expires_in' => $user->expiresIn,
                    'approved_scopes' => $user->approvedScopes,
                    'raw' => $user->getRaw(),
                    'token_set_matches' => $user->tokenSet->accessToken === $user->token,
                    'id_token' => $user->idToken->toArray(),
                    'id_token_matches' => $user->idToken === $user->tokenSet->idToken,
                ]);
            });
        });
    }

    public function testTheDriverIsRegisteredWithSocialite(): void
    {
        self::assertInstanceOf(AppsolutelyProvider::class, $this->socialite()->driver('appsolutely'));
    }

    public function testItRedirectsToTheAuthorizationEndpointWithPkceStateAndNonce(): void
    {
        $response = $this->get('/auth/appsolutely');

        $response->assertRedirect();
        $query = self::authorizationQuery($response);
        self::assertStringStartsWith(FakeOpenIdProvider::ISSUER . '/oauth/authorize?', (string) $response->headers->get('Location'));
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame(FakeOpenIdProvider::CLIENT_ID, $query['client_id'] ?? null);
        self::assertSame(self::CALLBACK, $query['redirect_uri'] ?? null);
        self::assertSame('openid email profile', $query['scope'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertNotEmpty($query['state'] ?? null);
        self::assertNotEmpty($query['nonce'] ?? null);
        self::assertNotEmpty($query['code_challenge'] ?? null);

        $pending = $this->sessionStore()->get(self::PENDING_SIGN_IN);
        self::assertIsArray($pending);
        self::assertSame($query['state'], $pending['state'] ?? null);
        self::assertSame($query['nonce'], $pending['nonce'] ?? null);
        // The verifier stays in the session; only its challenge leaves.
        self::assertIsString($pending['code_verifier'] ?? null);
        self::assertStringNotContainsString($pending['code_verifier'], (string) $response->headers->get('Location'));
    }

    public function testScopesAndParametersFollowSocialitesConventions(): void
    {
        $redirect = $this->driverWithSession()->scopes(['roles'])->scopes('email')->with(['prompt' => 'login'])->redirect();

        parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);
        self::assertSame('openid email profile roles', $query['scope'] ?? null);
        self::assertSame('login', $query['prompt'] ?? null);

        $redirect = $this->driverWithSession()->setScopes('openid')->redirectUrl('https://app.example.com/elsewhere')->redirect();

        parse_str((string) parse_url($redirect->getTargetUrl(), PHP_URL_QUERY), $query);
        self::assertSame('openid', $query['scope'] ?? null);
        self::assertSame('https://app.example.com/elsewhere', $query['redirect_uri'] ?? null);
    }

    public function testMaxAgeIsSentWithTheAuthorizationRequestAndKeptForTheCallback(): void
    {
        $response = $this->get('/auth/appsolutely/recent');

        self::assertSame('300', self::authorizationQuery($response)['max_age'] ?? null);
        $pending = $this->sessionStore()->get(self::PENDING_SIGN_IN);
        self::assertIsArray($pending);
        self::assertSame(300, $pending['max_age'] ?? null);
    }

    public function testWithoutMaxAgeNoneIsSent(): void
    {
        self::assertArrayNotHasKey('max_age', self::authorizationQuery($this->get('/auth/appsolutely')));
    }

    public function testACallbackForAnAuthenticationOlderThanMaxAgeIsRefused(): void
    {
        $this->withoutExceptionHandling();
        // Beyond max_age and the clock leeway the SDK allows on top of it.
        $authTime = Date::now()->getTimestamp() - 300 - (Config::DEFAULT_CLOCK_LEEWAY + 1);

        $this->expectException(IdTokenException::class);
        $this->expectExceptionMessage('max_age');

        $this->signIn(['auth_time' => $authTime], '/auth/appsolutely/recent');
    }

    public function testACallbackForARecentEnoughAuthenticationIsAccepted(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42']);

        $this->signIn(['auth_time' => Date::now()->getTimestamp() - 300], '/auth/appsolutely/recent')
            ->assertOk()
            ->assertJson(['id' => 'member-42']);
    }

    public function testMaxAgeMustNotBeNegative(): void
    {
        $this->expectException(InvalidArgumentValueException::class);

        $this->driverWithSession()->maxAge(-1)->redirect();
    }

    public function testASignInRoundTripReturnsTheVerifiedMember(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response([
            'sub' => 'member-42',
            'name' => 'Ada Lovelace',
            'preferred_username' => 'ada',
            'picture' => 'https://cdn.example.com/ada.png',
        ]);

        $user = $this->signIn(['email' => 'ada@example.com', 'email_verified' => true])->assertOk();

        $user->assertJson([
            'id' => 'member-42',
            'name' => 'Ada Lovelace',
            'nickname' => 'ada',
            'email' => 'ada@example.com',
            'avatar' => 'https://cdn.example.com/ada.png',
            'token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'approved_scopes' => ['openid', 'email', 'profile'],
            'token_set_matches' => true,
            'id_token_matches' => true,
        ]);
        self::assertSame('member-42', $user->json('id_token.claims.sub'));
        self::assertIsString($user->json('id_token.raw'));
        self::assertNull($this->sessionStore()->get(self::PENDING_SIGN_IN), 'The pending sign-in was not removed from the session.');

        $exchange = FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL)[0];
        $form = FakeOpenIdProvider::form($exchange);
        self::assertSame('authorization_code', $form['grant_type'] ?? null);
        self::assertSame('the-code', $form['code'] ?? null);
        self::assertSame(self::CALLBACK, $form['redirect_uri'] ?? null);
        self::assertNotEmpty($form['code_verifier'] ?? null);
        Http::assertSent(static fn(ClientRequest $request): bool => $request->url() === FakeOpenIdProvider::USERINFO_URL
            && $request->hasHeader('Authorization', 'Bearer access-token'));
    }

    public function testItAsksForUserInfoOnlyWhenEmailOrProfileWasRequested(): void
    {
        config()->set('appsolutely.socialite.scopes', ['openid']);

        $this->signIn()->assertOk()->assertJson(['id' => 'member-42', 'email' => null, 'name' => null]);

        self::assertSame([], FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::USERINFO_URL));
    }

    public function testAnUnverifiedEmailIsNotOfferedAsTheMembersEmail(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42']);

        $this->signIn(['email' => 'someone@example.com', 'email_verified' => false])
            ->assertOk()
            ->assertJson(['email' => null, 'raw' => ['email' => 'someone@example.com', 'email_verified' => false]]);
    }

    public function testAnEmailFromUserInfoIsVerifiedOnlyByUserInfosOwnFlag(): void
    {
        // The ID token vouches for its own address, not for the different
        // one UserInfo returns without saying whether it is verified.
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42', 'email' => 'other@example.com']);

        $this->signIn(['email' => 'ada@example.com', 'email_verified' => true])
            ->assertOk()
            ->assertJson(['email' => null, 'raw' => ['email' => 'other@example.com']]);
    }

    public function testAnEmailFromUserInfoMarkedVerifiedThereIsOffered(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42', 'email' => 'ada@example.com', 'email_verified' => true]);

        $this->signIn(['email_verified' => false])->assertOk()->assertJson(['email' => 'ada@example.com']);
    }

    public function testTheIdTokensEmailIsUsedWhenUserInfoCarriesNone(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42', 'email_verified' => false]);

        $this->signIn(['email' => 'ada@example.com', 'email_verified' => true])->assertOk()->assertJson(['email' => 'ada@example.com']);
    }

    public function testACallbackWithAnotherStateIsRefusedAndCannotBeRetried(): void
    {
        $this->withoutExceptionHandling();
        $this->get('/auth/appsolutely');
        $session = $this->sessionLeft();

        try {
            $this->withSession($session)->get('/auth/appsolutely/callback?code=the-code&state=forged&iss=' . urlencode(FakeOpenIdProvider::ISSUER));
            self::fail('A callback with a forged state was accepted.');
        } catch (AuthorizationResponseException) {
        }

        self::assertNull($this->sessionStore()->get(self::PENDING_SIGN_IN));
        self::assertSame([], FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
    }

    public function testACallbackWithoutASignInInProgressIsAnInvalidState(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(InvalidStateException::class);

        $this->get('/auth/appsolutely/callback?code=the-code&state=anything');
    }

    public function testAnErrorFromTheProviderReachesTheCaller(): void
    {
        $this->withoutExceptionHandling();
        $query = self::authorizationQuery($this->get('/auth/appsolutely'));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('access_denied');

        $this->withSession($this->sessionLeft())->get('/auth/appsolutely/callback?' . http_build_query([
            'error' => 'access_denied',
            'state' => $query['state'] ?? '',
            'iss' => FakeOpenIdProvider::ISSUER,
        ]));
    }

    public function testTheUserIsExchangedOnceAndThenRemembered(): void
    {
        $this->provider->userInfo = static fn(): PromiseInterface => Http::response(['sub' => 'member-42']);
        $request = $this->requestWithSession();
        $driver = $this->driver()->setRequest($request);
        $query = self::authorizationQuery($driver->redirect());
        $this->provider->token = $this->tokens($query, []);
        $request->query->replace(['code' => 'the-code', 'state' => $query['state'] ?? '', 'iss' => FakeOpenIdProvider::ISSUER]);

        self::assertSame($driver->user(), $driver->user());
        self::assertCount(1, FakeOpenIdProvider::requestsTo(FakeOpenIdProvider::TOKEN_URL));
    }

    public function testStatelessUseIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('session');

        $this->driver()->stateless();
    }

    public function testAMissingRedirectUrlNamesItsEnvironmentVariable(): void
    {
        config()->set('appsolutely.socialite.redirect', null);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_REDIRECT_URI');

        $this->driverWithSession()->redirect();
    }

    private function socialite(): SocialiteManager
    {
        $socialite = $this->app?->make(Factory::class);
        self::assertInstanceOf(SocialiteManager::class, $socialite);

        return $socialite;
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function missingSignInSettings(): iterable
    {
        yield 'no sign-in client' => [['appsolutely.client_id', 'appsolutely.client_secret'], 'APPSOLUTELY_CLIENT_ID, APPSOLUTELY_CLIENT_SECRET'];
        yield 'nothing for sign-in' => [['appsolutely.issuer', 'appsolutely.client_id', 'appsolutely.client_secret'], 'APPSOLUTELY_ISSUER, APPSOLUTELY_CLIENT_ID, APPSOLUTELY_CLIENT_SECRET'];
    }

    /**
     * @param list<string> $unset
     */
    #[DataProvider('missingSignInSettings')]
    public function testTheDriverWithoutSignInSettingsFailsNamingThem(array $unset, string $named): void
    {
        foreach ($unset as $key) {
            config()->set($key, null);
        }

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The appsolutely Socialite driver signs members in and needs ' . $named . ' set');

        $this->socialite()->forgetDrivers()->driver('appsolutely');
    }

    private function driver(): AppsolutelyProvider
    {
        // A fresh driver, as Socialite builds one per request in a real application.
        $driver = $this->socialite()->forgetDrivers()->driver('appsolutely');
        self::assertInstanceOf(AppsolutelyProvider::class, $driver);

        return $driver;
    }

    /**
     * What the previous request left in the session, to send with the next.
     *
     * @return array<mixed>
     */
    private function sessionLeft(): array
    {
        return $this->sessionStore()->all();
    }

    private function sessionStore(): Session
    {
        $session = $this->app?->make(Session::class);
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    /**
     * A driver outside a route, on a request carrying the session as the
     * web middleware group would give it.
     */
    private function driverWithSession(): AppsolutelyProvider
    {
        return $this->driver()->setRequest($this->requestWithSession());
    }

    private function requestWithSession(): Request
    {
        $request = Request::create(self::CALLBACK);
        $request->setLaravelSession($this->sessionStore());

        return $request;
    }

    /**
     * Starts a sign-in, then comes back to the callback the way the browser
     * would, with the session the first request left.
     *
     * @param array<string, mixed> $claims
     * @param string $start the route that starts the sign-in
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function signIn(array $claims = [], string $start = '/auth/appsolutely'): TestResponse
    {
        $query = self::authorizationQuery($this->get($start));
        $this->provider->token = $this->tokens($query, $claims);

        return $this->withSession($this->sessionLeft())->get('/auth/appsolutely/callback?' . http_build_query([
            'code' => 'the-code',
            'state' => $query['state'] ?? '',
            'iss' => FakeOpenIdProvider::ISSUER,
        ]));
    }

    /**
     * @param array<string, string> $query the authorization request's query
     * @param array<string, mixed> $claims
     * @return \Closure(): PromiseInterface
     */
    private function tokens(array $query, array $claims): \Closure
    {
        $idToken = $this->provider->rsa->sign($this->provider->claims(['nonce' => $query['nonce'] ?? '', ...$claims]));

        return static fn(): PromiseInterface => Http::response([
            'access_token' => 'access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'refresh-token',
            'scope' => $query['scope'] ?? '',
            'id_token' => $idToken,
        ]);
    }

    /**
     * @param RedirectResponse|TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @return array<string, string>
     */
    private static function authorizationQuery(RedirectResponse|TestResponse $response): array
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}

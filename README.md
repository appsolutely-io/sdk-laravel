# Appsolutely SDK for Laravel

The Laravel bridge for [`appsolutely/sdk-php`](https://github.com/appsolutely-io/sdk-php), the client of one Appsolutely site: configuration, container bindings and a facade for the client and the site's API, a Socialite driver for OpenID Connect sign-in, a verified webhook route that dispatches Laravel events, an Artisan command that checks the configuration, and test helpers for the API and for webhook deliveries.

## Requirements

- PHP 8.3 or newer
- a site running aio commit `c44982c39` (on aio's default branch after v0.43.0) or newer, the API documents `appsolutely/sdk-php` is held to
- `appsolutely/sdk-php` from its `main` branch (`^0.1@dev`)
- Laravel 12.69 or newer, or Laravel 13.30 or newer

## Installation

Neither package is on Packagist or has a release yet, so Composer installs both from their GitHub repositories' `main` branches. Add both to your application's `composer.json`, and allow the `php-http/discovery` Composer plugin, which `sdk-php` depends on:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/appsolutely-io/sdk-php" },
        { "type": "vcs", "url": "https://github.com/appsolutely-io/sdk-laravel" }
    ],
    "config": {
        "allow-plugins": {
            "php-http/discovery": true
        }
    }
}
```

Both SDK repositories are public; Composer can read them without a GitHub token.

```bash
composer require "appsolutely/sdk-laravel:^0.1@dev" "appsolutely/sdk-php:^0.1@dev"
```

Each `main` branch is aliased as `0.1.x-dev`. This package requires `sdk-php` as `^0.1@dev`, but Composer applies a stability flag only from the root `composer.json`, so the application names both with `@dev` until their first `0.1` releases; drop `@dev` then.

The service provider, the `Appsolutely` facade and the Socialite driver are registered by package discovery. Publish the configuration file if you want to edit it:

```bash
php artisan vendor:publish --tag=appsolutely-config
```

## Configuration

| Variable | Key in `config/appsolutely.php` | Meaning | Default |
| --- | --- | --- | --- |
| `APPSOLUTELY_BASE_URL` | `base_url` | The site's origin, such as `https://shop.example.com`, without a path | required |
| `APPSOLUTELY_ISSUER` | `issuer` | The site's sign-in host, exactly as its discovery document names it | required for sign-in and the backend |
| `APPSOLUTELY_CLIENT_ID` | `client_id` | The OAuth client id that signs members in | required for sign-in |
| `APPSOLUTELY_CLIENT_SECRET` | `client_secret` | That client's secret; set with its id, or not at all | required for sign-in |
| `APPSOLUTELY_BACKEND_CLIENT_ID` | `backend.client_id` | The machine client registered for this application's backend, which `Appsolutely::backend()` calls as | none: the backend is refused |
| `APPSOLUTELY_BACKEND_CLIENT_SECRET` | `backend.client_secret` | That client's secret; set with its id, or not at all | none |
| `APPSOLUTELY_BACKEND_SCOPES` | `backend.scopes` | The scopes to ask the backend's token for, space-separated | every scope the client is registered with |
| `APPSOLUTELY_BACKEND_CLIENT_AUTHENTICATION` | `backend.client_authentication` | How the machine client authenticates: `client_secret_basic` or `client_secret_post` | as `APPSOLUTELY_CLIENT_AUTHENTICATION` |
| `APPSOLUTELY_ADMIN_CLIENT_ID` | `admin.client_id` | An administrator credential: the OAuth client an administrator issued on the site, which `Admin\AdminClient` calls the Administrator API with | none: `AdminClient` is refused |
| `APPSOLUTELY_ADMIN_CLIENT_SECRET` | `admin.client_secret` | That client's secret | none |
| `APPSOLUTELY_ADMIN_SCOPES` | `admin.scopes` | The Administrator API scopes to ask its token for, space-separated, such as `admin:articles:read admin:articles:write` | none |
| `APPSOLUTELY_ADMIN_CLIENT_AUTHENTICATION` | `admin.client_authentication` | Administrator token endpoint authentication: `client_secret_basic` or `client_secret_post` | `client_authentication` |
| `APPSOLUTELY_CLIENT_AUTHENTICATION` | `client_authentication` | `client_secret_basic` or `client_secret_post` | `client_secret_basic` |
| `APPSOLUTELY_CLOCK_LEEWAY` | `clock_leeway` | Seconds of clock disagreement tolerated on ID tokens (0 to 300) | `60` |
| `APPSOLUTELY_CACHE_STORE` | `cache_store` | The cache store for discovery, signing keys and handled webhook ids | the default store |
| `APPSOLUTELY_HTTP_TIMEOUT` | `http.timeout` | Seconds a request to the site or its issuer may take, end to end (above 0, at most 600) | `30` |
| `APPSOLUTELY_HTTP_CONNECT_TIMEOUT` | `http.connect_timeout` | Seconds to connect, TLS included (above 0, at most the request timeout) | `10` |
| `APPSOLUTELY_HTTP_RETRIES` | `http.retries.max` | Retries of a Site API call that may safely be repeated (0 to 10; 0 turns retrying off) | `2` |
| `APPSOLUTELY_HTTP_RETRY_BASE_DELAY` | `http.retries.base_delay` | Seconds of the first backoff, doubled on each further one | `0.5` |
| `APPSOLUTELY_HTTP_RETRY_MAX_DELAY` | `http.retries.max_delay` | The longest single wait in seconds (1 to 300); a longer `Retry-After` fails the call instead | `30` |
| `APPSOLUTELY_REDIRECT_URI` | `socialite.redirect` | Where sign-in returns: an absolute URL, or a path on this application | required for sign-in |
| `APPSOLUTELY_WEBHOOK_SECRETS` | `webhooks.secrets` | The endpoint's `whsec_` secrets, comma-separated (two during a rotation) | required for webhooks |
| `APPSOLUTELY_WEBHOOK_TOLERANCE` | `webhooks.tolerance` | Seconds a delivery's timestamp may differ from now (1 to 3600) | `300` |
| `APPSOLUTELY_WEBHOOK_PATH` | `webhooks.path` | Where `Route::appsolutelyWebhooks()` listens when given no path | `appsolutely/webhooks` |
| `APPSOLUTELY_WEBHOOK_DELIVERIES_NAMESPACE` | `webhooks.deliveries_namespace` | Keeps this endpoint's handled ids apart from another endpoint's in the same store | empty |

An application that never signs members in leaves `APPSOLUTELY_CLIENT_ID` and `APPSOLUTELY_CLIENT_SECRET` unset, and needs `APPSOLUTELY_ISSUER` only for `Appsolutely::backend()` or `AdminClient`: `Appsolutely::api()` and `appsolutely:check` work without them, and the `appsolutely` Socialite driver refuses to start, naming what to set.

`APPSOLUTELY_CLIENT_ID` and `APPSOLUTELY_CLIENT_SECRET` are a pair, as are `APPSOLUTELY_BACKEND_CLIENT_ID` and `APPSOLUTELY_BACKEND_CLIENT_SECRET`: set both, or neither; one without the other is refused when the client is first built.

`client_authentication`, `backend.client_authentication` and `clock_leeway` are named as the SDK's `Config` names them (`clientAuthentication`, `backendClientAuthentication`, `clockLeeway`).

Settings are read when they are first used, not at boot: an application that has not configured the package still boots, and the error names the variable to set.

Use a cache store that outlives a request (Redis, Memcached, the database or files; not `array`): otherwise every request fetches discovery again, and a retried webhook delivery is handled twice.

Under Octane, the `appsolutely` driver is built per request: Octane's `PrepareSocialiteForNextOperation` listener, which the default `config/octane.php` pulls in through `Octane::prepareApplicationForNextOperation()` for every request, task and tick, calls `forgetDrivers()` on the Socialite manager before every operation, so a driver and the request it holds never outlive the request, whether or not Socialite is warmed. Keep that line if you edit the file. Do not list `Appsolutely\Sdk\Client` in `octane.warm`: a warmed client is built when the worker boots, so a missing setting would stop every worker from starting instead of failing only the call that needs it.

## The client and the Site API

`Appsolutely\Sdk\Client` is a singleton in the container, also behind the `Appsolutely` facade, and can be injected. It calls the site's `/api/v1` through one typed method per operation, on the client of the audience that may call it: `backend()` as this application's backend, `forMember($accessToken)->api()` as a signed-in member, and `api()` for what anyone may call (`version()`, `openApiDocument()` and `magicLink()`), sent without a credential; `Appsolutely\Sdk\Api\PublicApi` can also be injected. Each has `raw()` for untyped calls on paths the client has no method for. An administrator's work is not in the Site API: the SDK's `Admin\AdminClient` calls the site's Administrator API with an administrator credential.

```php
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;

foreach (Appsolutely::backend()->products()->list() as $product) {
    // $product->prices, one per currency, in minor units
}

final class DeliverLicence
{
    public function __construct(private Client $appsolutely) {}

    public function __invoke(string $deliveryToken, string $licenceKey): void
    {
        $this->appsolutely->backend()->fulfilments()->create($deliveryToken, $licenceKey, channel: 'email');
    }
}
```

`Appsolutely\Sdk\Admin\AdminClient` is a singleton in the container too, built on first use from the administrator credential (`APPSOLUTELY_ADMIN_CLIENT_ID`, `APPSOLUTELY_ADMIN_CLIENT_SECRET` and `APPSOLUTELY_ADMIN_SCOPES`, with `APPSOLUTELY_ISSUER`); resolving it without them is refused, naming what to set. Its token is kept in the configured cache and asked for under the same lock as the backend's. The site's Administrator API must be switched on:

```php
use Appsolutely\Sdk\Admin\AdminClient;

$read = app(AdminClient::class)->articles()->get($articleId);
app(AdminClient::class)->articles()->update($articleId, $read->etag, ['title' => 'Launched']);
```

To call the API as a signed-in member, pass the access token their sign-in returned:

```php
$me = Appsolutely::forMember($accessToken)->api()->me()->get();
```

`backend()` calls the site as this application's backend, with a token its machine client is granted: kept in the configured cache until shortly before it expires, and asked for again once when the site no longer accepts it. A new token is asked for under `Cache::lock()` in the same store, so when it falls due, one process or worker asks while the rest wait for it rather than each asking at once; use a store whose locks reach every server that shares the token (Redis, Memcached, the database, DynamoDB); the file store locks within one host only.

```php
$checkout = Appsolutely::backend()->checkoutSessions()->create($subject, [['product' => 'team', 'quantity' => 5]], $successUrl, $cancelUrl);

return redirect()->away($checkout->value->url);
```

Refusals are the SDK's `ApiException` subclasses (`NotFoundException`, `ValidationFailedException`, ...). Calls that may safely be repeated, reads and keyed writes, are retried as sdk-php's `RetryPolicy` describes (after a network failure or a passing refusal from the site or a gateway, and a read also after a `500`), as often and as long as the `APPSOLUTELY_HTTP_RETRIES` settings allow; the waits go through Laravel's `Sleep`, so `Sleep::fake()` skips them in tests. Every request is bounded by `APPSOLUTELY_HTTP_TIMEOUT` and `APPSOLUTELY_HTTP_CONNECT_TIMEOUT`, so a site that stops answering cannot hold a worker.

The client sends its requests through Laravel's HTTP client, so `Http::fake()`, `Http::preventStrayRequests()`, global HTTP middleware and options, and the request events seen by Telescope and Pulse apply to them; the application's global `Authorization`, `Proxy-Authorization` and `Cookie` headers are never sent. It reads the time from Laravel's clock (`travelTo()` moves it) and logs through Laravel's logger. See the [`sdk-php` README](https://github.com/appsolutely-io/sdk-php#readme) for every operation and for everything `oidc()` offers.

## Signing members in with Socialite

```php
use Laravel\Socialite\Facades\Socialite;

Route::get('/auth/appsolutely', fn () => Socialite::driver('appsolutely')->redirect());

Route::get('/auth/appsolutely/callback', function () {
    $member = Socialite::driver('appsolutely')->user();

    $user = User::updateOrCreate(['appsolutely_id' => $member->getId()], [
        'name' => $member->getName(),
        'email' => $member->getEmail(),
        // What a later refresh needs, kept server-side.
        'appsolutely_refresh_token' => $member->refreshToken,
        'appsolutely_id_token' => $member->idToken->toArray(),
    ]);
    Auth::login($user);

    return redirect('/dashboard');
});
```

The refresh token is a long-lived credential and the ID token is stored with it. Keep both server-side: in database columns, as above, cast `encrypted` and `encrypted:array` on the model; or in a server-side session driver (`database`, `redis`, `file`). Never use the `cookie` session driver for them, which sends the session to the browser.

Both routes need the session, so put them in the `web` group. In production, set `APPSOLUTELY_REDIRECT_URI` to the absolute URL registered for the client: a path is turned into a URL from the scheme and host of the current request, which behind a proxy or load balancer depends on your trusted proxies, and OpenID Connect requires the redirect to match the registered one exactly. The SDK runs the whole OpenID Connect code flow: PKCE S256, `state`, `nonce`, the RFC 9207 issuer check and ID token verification. `stateless()` is refused, because those values live in the session between the two requests.

- The scopes are `openid email profile` unless you change `appsolutely.socialite.scopes` or call `scopes()` / `setScopes()`; `with()` adds authorization parameters such as `prompt` or `login_hint`, and `redirectUrl()` overrides the configured redirect.
- `maxAge(300)` asks the provider to sign the member in again when they last authenticated more than 300 seconds ago, and the callback refuses an ID token whose `auth_time` is older (with the clock leeway); use it before sensitive actions.
- The user comes from the verified ID token, plus the UserInfo claims when `email` or `profile` was requested; `getRaw()` returns all of them.
- **`getEmail()` is the address only when the provider marked it verified** (`email_verified`), read from the same claims as the address: UserInfo's when UserInfo returns an `email` (no `email_verified` there means not verified), the ID token's otherwise. An unverified address stays in `getRaw()['email']`; do not link accounts on it.
- `$member->tokenSet` is the SDK's `TokenSet`; `$member->idToken` is the SDK's verified `IdToken`. Store `$member->idToken->toArray()` and rebuild it with `IdToken::fromTrustedStorage()` to refresh later.
- A callback without a sign-in in progress throws Socialite's `InvalidStateException`. Every other failure is an `Appsolutely\Sdk\Exception\AppsolutelyException` (an `OAuthException` when the member declined, for example). Never sign the member in after an exception. The sign-in in progress is removed from the session before the exchange, so a callback URL works at most once.

To refresh later:

```php
use Appsolutely\Sdk\Oidc\IdToken;

$tokens = Appsolutely::oidc()->refresh($user->appsolutely_refresh_token, IdToken::fromTrustedStorage($user->appsolutely_id_token));
```

## Webhooks

Register the endpoint in `routes/api.php`, so it runs in the `api` middleware group: a delivery needs no session and no cookie, and the `web` group would start a session and set a cookie for every one.

```php
Route::appsolutelyWebhooks();                        // /api/ + APPSOLUTELY_WEBHOOK_PATH
Route::appsolutelyWebhooks('/integrations/appsolutely');
```

The route is a `POST` named `appsolutely.webhooks` that opts out of CSRF protection itself, so it also works in the `web` group without a CSRF exception. The `api` group throttles only when your application calls `throttleApi()` in `bootstrap/app.php`; if it does, deliveries count against that limit, and a `429` is a failed attempt the sender retries later. Exempt the route if bursts of deliveries could reach the limit:

```php
Route::appsolutelyWebhooks()->withoutMiddleware('throttle:api');
```

Every verified delivery is dispatched as `Appsolutely\Sdk\Laravel\Events\WebhookReceived`, whatever its type. When the SDK knows the type, the delivery is then dispatched again as the SDK's typed event from `Appsolutely\Sdk\Webhooks\Events` (`OrderPaidEvent`, `SubscriptionEvent`, `AccountEvent`, `PingEvent`, ...), with times in UTC, ids as strings and amounts in minor units; a type the SDK does not know is dispatched only as `WebhookReceived`. Listen to whichever fits:

```php
use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Webhooks\Events\OrderPaidEvent;

Event::listen(function (OrderPaidEvent $paid) {
    FulfilOrder::dispatch($paid->order->id, $paid->order->totalAmount, $paid->order->currency);
});

Event::listen(function (WebhookReceived $received) {
    $event = $received->event;  // id, type, timestamp, version, mode, data as decoded
});
```

`$typed->envelope` is the same verified delivery as `WebhookReceived::$event`, and the type names are constants on `Appsolutely\Sdk\Webhooks\EventType`.

| The delivery | The answer |
| --- | --- |
| Fails verification (signature, timestamp, a body id other than the `webhook-id` header) | `400`, the reason in your log |
| Is of a type the SDK knows but its data does not have that type's shape | `500`, the exception reported, no listener run |
| Has an id already handled (a retry or a redelivery) | `200`, listeners not run |
| Has an id another attempt is handling at this moment | `409`, listeners not run, the id not recorded, so the site retries it later |
| Verified and new, listeners return | `200`, the id is recorded as handled |
| Verified and new, a listener throws | `500`, the exception reported, the id not recorded, so the site resends it and the resend runs every listener again |

Make every listener idempotent, keyed on the delivery id (`$event->id`, or `$typed->envelope->id`). The delivery counts as handled only when every listener returns: when any listener throws, `WebhookReceived` or typed, the site resends the delivery, and the resend runs every listener again, including those that succeeded the first time.

Answer within a few seconds: queue slow work (a queued listener counts as handled once it is queued). The endpoint never redirects, which the sender treats as a failed attempt, and never answers `410 Gone`, which would switch the endpoint off; keep any middleware you add in front of it from doing either.

Each delivery is handled under a `Cache::lock()` on its id in the configured store, held until the listeners return or throw (60 seconds at most), so two attempts of one delivery arriving together do not both run the listeners: the second is answered `409 Conflict`. Standard Webhooks retries every attempt that is not answered with a `2xx`, so the sender sends it again later, when it is acknowledged as handled, or handled if the first attempt failed. Use a store that supports locks; on one that does not, the deliveries are handled without it.

When two applications or two endpoints share one cache store, give each its own `APPSOLUTELY_WEBHOOK_DELIVERIES_NAMESPACE`, or one would skip a delivery the other handled.

## Checking the configuration

```bash
php artisan appsolutely:check
```

The command warns when a published configuration still sets `api_token` (`APPSOLUTELY_API_TOKEN`): the setting is ignored, and administrator calls need an administrator credential. This warning does not fail the check. A partial administrator client id/secret pair fails naming only the missing variable; an absent pair is reported as not configured and is optional. A complete credential's local settings are checked without fetching an administrator token.

The command reads the site's API version (`GET /api/v1`) at the configured base URL and, when member sign-in is configured, the issuer's discovery document, then prints the version and its lifecycle (status, deprecation, sunset, successor) and the issuer's endpoints. It then writes, reads back and deletes a throwaway key in the configured cache store, on its own `Cache store` line, so a store that is down or refuses writes fails the check rather than the first call that needs it. When a backend client is configured, the command fetches a new token from the issuer, never the one kept in the cache and without replacing it there, and reads `GET /api/v1/client` with it, reported on its own `Backend client` line: a wrong issuer, a refused or rotated secret, or a grant missing any configured scope fails the check here rather than on the first backend call in production. An OAuth refusal is shown by its error code only, never the provider's description, which may echo what it was sent. Any other refusal, including a 401 or 403 to the version read (from a proxy in front of the site, say), fails naming the request the site refused and its status. It never prints the token. A version whose status is `deprecated`, or that has a deprecation or sunset date, is a warning that states only the dates and successor the site gave; anything that fails exits non-zero with the reason, so the command can gate a deployment.

## Testing

`Appsolutely::fake()` replaces the client, behind the facade and in the container, with the SDK's `FakeClient`: arrange the answer to each operation, run the code under test, then read the calls it made. Answers go through the real models, so one that does not match the API document fails as a real one would, and no Appsolutely setting is needed:

```php
use Appsolutely\Sdk\Api\Operation;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;

$fake = Appsolutely::fake()->answer(Operation::CreateFulfilment, ['token' => 'd_1', 'status' => 'delivered', 'delivered_at' => null]);

app(DeliverLicence::class)('d_1', 'LICENCE-1');   // the injected Client is the fake's

$this->assertSame('LICENCE-1', $fake->lastCall(Operation::CreateFulfilment)->body['delivery_payload'] ?? null);
```

The fake grants its backend a token in memory, so no backend setting is needed. `refuse()` arranges a problem instead, such as `->refuse(Operation::CreateFulfilment, 'conflict', 409, 'The delivery is no longer pending.')`, which the code under test then receives as `ConflictException`.

Sign-in is not faked. Its requests go through Laravel's HTTP client, so fake them like any other:

```php
Http::fake([
    'login.example.com/.well-known/openid-configuration' => Http::response([...]),
    'login.example.com/oauth/token' => Http::response(['access_token' => '...', 'token_type' => 'Bearer', 'expires_in' => 600]),
]);
```

To send webhook deliveries signed exactly as Appsolutely signs them, use the `InteractsWithAppsolutelyWebhooks` trait. It signs with the configured secrets on Laravel's clock and posts to the registered route. It needs `illuminate/testing`, which `laravel/framework` includes; the package itself does not require it:

```php
use Appsolutely\Sdk\Laravel\Testing\InteractsWithAppsolutelyWebhooks;
use Appsolutely\Sdk\Webhooks\EventType;
use Illuminate\Support\Facades\Queue;

class PaidOrderWebhookTest extends TestCase
{
    use InteractsWithAppsolutelyWebhooks;

    public function test_a_paid_order_is_fulfilled(): void
    {
        Queue::fake();

        // The data in the shape the site sends, which OrderPaidEvent reads.
        $this->postAppsolutelyWebhook(EventType::ORDER_PAID, [
            'id' => 'ord_1', 'reference' => 'A-1001', 'mode' => 'production', 'currency' => 'USD',
            'amount' => 4900, 'discounted_amount' => 0, 'shipping_amount' => 0, 'tax_amount' => 0,
            'tax_included' => false, 'total_amount' => 4900, 'total_shown' => '$49.00', 'items' => [],
        ])->assertOk();

        Queue::assertPushed(FulfilOrder::class);

        $delivery = $this->appsolutelyWebhookFactory()->make(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1']);
        $this->sendAppsolutelyWebhook($delivery)->assertOk();
        $this->sendAppsolutelyWebhook($delivery)->assertOk();  // a duplicate: acknowledged, not handled again
    }
}
```

`FulfilOrder` stands for your application's own queued job, the one the `OrderPaidEvent` listener above dispatches.

Without the trait, `Appsolutely\Sdk\Testing\WebhookFactory` from `sdk-php` builds the delivery; send its raw body and headers with `call()`:

```php
$delivery = (new WebhookFactory(['whsec_...']))->make(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1']);

$this->call(
    'POST',
    route('appsolutely.webhooks'),
    server: $this->transformHeadersToServerVars([...$delivery->headers, 'Content-Type' => 'application/json']),
    content: $delivery->body,
);
```

Do not use `postJson()` with a decoded body: it encodes the body again, and the signature covers the exact bytes.

## Design decisions

The [decision records](docs/decisions/README.md) explain the implementation choices and their tradeoffs.

## License

MIT — see [LICENSE](LICENSE).

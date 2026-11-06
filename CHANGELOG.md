# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first release is being prepared. It requires `appsolutely/sdk-php` from its `main` branch (`^0.1@dev`), the client held to aio commit `c44982c39` on aio's default branch, until that package's first release.

### Added

- An auto-discovered service provider binding `Appsolutely\Sdk\Client`, the client of one site, as a singleton built on first use from a publishable `config/appsolutely.php` read from environment variables: the base URL, the issuer, the member sign-in client, the backend's machine client and its scopes, the client authentication of each, timeouts, retries and the cache store. Missing or invalid settings are refused naming the variable to set.
- `Appsolutely\Sdk\Api\PublicApi` bound to the shared client's `api()`, and the `Appsolutely` facade over the client, so `Appsolutely::api()`, `Appsolutely::backend()` and `Appsolutely::forMember($accessToken)` reach the Site API.
- `Appsolutely\Sdk\Admin\AdminClient` bound as a singleton, built on first use from an administrator credential (`APPSOLUTELY_ADMIN_CLIENT_ID`, `APPSOLUTELY_ADMIN_CLIENT_SECRET`, `APPSOLUTELY_ADMIN_SCOPES`, and optionally `APPSOLUTELY_ADMIN_CLIENT_AUTHENTICATION`). Resolving it without them is refused naming what to set; an application that never calls the Administrator API is not asked for them.
- The SDK's PSR services from Laravel: its HTTP client sends through Laravel's HTTP client, so `Http::fake()` and `Http::preventStrayRequests()` cover it, and the application's global `Authorization`, `Proxy-Authorization` and `Cookie` headers are never sent to the site; its cache, clock, sleeper and logger are Laravel's. The SDK's `Cache\TokenLock` is a `Cache::lock()` in the configured store, so processes sharing a token ask for a new one once.
- An `appsolutely` Socialite driver running the OpenID Connect code flow through the SDK: the authorization request kept in the session, a Socialite user with the verified claims, the SDK token set and the `IdToken` for a later refresh, `getEmail()` returning only a verified address, and `maxAge()` to ask for a recent authentication.
- `Route::appsolutelyWebhooks()`, a CSRF-exempt route that verifies deliveries with the SDK, dispatches `Events\WebhookReceived` and the SDK's typed event for each delivery not yet handled, under a lock per delivery, and answers so that a failed listener is retried.
- The `appsolutely:check` Artisan command, which reads the site's API version and lifecycle, the issuer's discovery document, proves the cache store and the backend client's credential, and checks an administrator credential's local settings, failing with the reason so it can gate a deployment. It never prints a secret or a token.
- `Appsolutely::fake()`, which swaps the SDK's `Testing\FakeClient` in behind the facade and the container, and the `Testing\InteractsWithAppsolutelyWebhooks` trait to send signed deliveries in feature tests.
- A conflict with `firebase/php-jwt` below 7.0.5, whose JWK parsing fails under OpenSSL 3.

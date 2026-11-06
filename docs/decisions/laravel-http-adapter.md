# Adapt Laravel requests at the public send boundary

## Decision

Implement PSR-18 through Laravel's pending request and `send()` path, creating a pending request per call. Reconcile headers after global options have been merged.

## Rationale

The SDK must participate in Laravel fakes, stray-request prevention, middleware and request events. Calling the underlying Guzzle client bypasses options Laravel's handlers require. Keeping a pending request on the adapter would retain the factory's fakes from construction and miss fakes registered later.

Global headers are another boundary: the SDK request supplies its own credentials, and the application's global credentials can belong to another service. Case-insensitive replacement on the outgoing PSR request prevents merged global values from leaking alongside them. Raw body options leave Content-Type under the request's control instead of adding a second value through `withBody()`.

## Alternatives and costs

Using the underlying transport loses Laravel integration. Recursive header merging can duplicate a Content-Type or send an unrelated credential. A long-lived pending request misses later test setup. The adapter therefore owns header canonicalization, credential removal and exception translation. Redirects and error responses are returned for the SDK to judge, as [PSR-18](https://www.php-fig.org/psr/psr-18/) requires; connection failures become network exceptions and requests refused before sending become client exceptions.

## Current implementation

[`LaravelHttpClient::sendRequest()`](../../src/Http/LaravelHttpClient.php) builds the pending request and settles outgoing headers. [`LaravelHttpClientTest`](../../tests/Http/LaravelHttpClientTest.php) checks body/header preservation, global credentials, fakes and exception mapping. The [client guide](../../README.md#the-client-and-the-site-api) explains the observable Laravel integration and configurable timeouts.

# Delegate the Socialite code flow to the SDK

## Decision

Implement Socialite's provider contract and delegate the whole OpenID Connect flow to the PHP SDK. Keep the pending authorization request as plain session values under a private key.

## Rationale

Extending Socialite's OAuth provider would introduce a second owner for state, PKCE and token handling while still needing OpenID Connect nonce, issuer and ID token verification. Composition gives those checks one owner. Plain values work with JSON session serialization, and pulling the pending request before exchange makes a callback single-use even on failure. The session key is an implementation detail, not an API for applications to alter the verifier's inputs.

## Alternatives and costs

Inheriting the OAuth flow duplicates security state. Making the session key public commits the package to an internal name and invites writes to nonce, state or verifier data. Supporting stateless callbacks would discard the saved values that authenticate the response, so the driver refuses that mode. Applications must provide a session and begin a new sign-in after a failed consumed callback. The driver implements the Socialite convenience methods it exposes rather than inheriting the entire OAuth provider API.

## Current implementation

[`AppsolutelyProvider`](../../src/Socialite/AppsolutelyProvider.php) implements `Provider`; `redirect()` stores plain values and `pendingSignIn()` consumes them. [`SocialiteProviderTest`](../../tests/Socialite/SocialiteProviderTest.php) exercises the flow. The [sign-in guide](../../README.md#signing-members-in-with-socialite) covers verified-only email mapping, token storage and callback errors; those policies are not duplicated here.

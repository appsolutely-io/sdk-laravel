<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Socialite;

use Appsolutely\Sdk\Oidc\IdToken;
use Appsolutely\Sdk\Oidc\TokenSet;
use Laravel\Socialite\Two\User;

/**
 * The member a sign-in returned: Socialite's user, mapped from the verified
 * ID token and, when `email` or `profile` was requested, the UserInfo
 * claims, which getRaw() returns.
 *
 * getEmail() is the address only when the provider marked it verified;
 * an unverified one stays in getRaw() under `email`.
 */
final class AppsolutelyUser extends User
{
    /**
     * @internal built by AppsolutelyProvider::user()
     */
    public function __construct(
        /** The SDK's token set: access, refresh and ID token, expiry and granted scope. */
        public readonly TokenSet $tokenSet,
        /**
         * The sign-in's verified ID token, which a later
         * OpenIdClient::refresh() needs besides the refresh token. Store
         * toArray() and rebuild it with IdToken::fromTrustedStorage(); it
         * holds the raw ID token, so keep it server-side with the refresh
         * token, never in a cookie.
         */
        public readonly IdToken $idToken,
    ) {}
}

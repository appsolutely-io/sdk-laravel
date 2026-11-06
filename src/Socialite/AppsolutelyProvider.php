<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Socialite;

use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Exception\IdTokenException;
use Appsolutely\Sdk\Laravel\Settings;
use Appsolutely\Sdk\Oidc\AuthorizationRequest;
use Appsolutely\Sdk\Oidc\IdToken;
use Appsolutely\Sdk\Oidc\TokenSet;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\InvalidStateException;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * The `appsolutely` Socialite driver. Socialite's own OAuth 2.0 provider is
 * not used: the SDK runs the whole OpenID Connect code flow (PKCE S256,
 * state, nonce, the RFC 9207 issuer check and ID token verification), and
 * this class only keeps the request it started in the session between the
 * redirect and the callback.
 *
 * Exceptions from the callback are the SDK's (Appsolutely\Sdk\Exception):
 * never sign the member in after one.
 */
final class AppsolutelyProvider implements Provider
{
    /** Where the authorization request waits for the callback. */
    private const string SESSION_KEY = 'appsolutely.sign_in';

    /** Scopes whose claims the UserInfo endpoint answers. */
    private const array USERINFO_SCOPES = ['email', 'profile'];

    /** @var list<string> */
    private array $scopes;

    /** @var array<string, string> */
    private array $parameters = [];

    private ?string $redirectUrl = null;

    private ?int $maxAge = null;

    private ?AppsolutelyUser $user = null;

    /**
     * @internal built by Socialite::driver('appsolutely')
     */
    public function __construct(
        private Request $request,
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly UrlGenerator $urls,
        private readonly ClockInterface $clock,
    ) {
        $this->scopes = $settings->scopes();
    }

    public function redirect(): RedirectResponse
    {
        $authorization = $this->client->oidc()->authorizationUrl(
            redirectUri: $this->redirectUri(),
            scopes: $this->scopes,
            parameters: $this->parameters,
            maxAge: $this->maxAge,
        );

        $this->request->session()->put(self::SESSION_KEY, [
            'url' => $authorization->url,
            'redirect_uri' => $authorization->redirectUri,
            'state' => $authorization->state,
            'nonce' => $authorization->nonce,
            'code_verifier' => $authorization->codeVerifier,
            'max_age' => $authorization->maxAge,
            'scopes' => $this->scopes,
        ]);

        return new RedirectResponse($authorization->url);
    }

    /**
     * Exchanges the callback's code and returns the member the verified ID
     * token describes. The sign-in in progress is taken out of the session
     * first, so a callback is used at most once, whether it succeeds or not.
     */
    public function user(): AppsolutelyUser
    {
        if ($this->user !== null) {
            return $this->user;
        }

        [$authorization, $scopes] = $this->pendingSignIn();
        $oidc = $this->client->oidc();
        $tokens = $oidc->exchangeCallback($this->request->query(), $authorization);
        $idToken = $tokens->idToken ?? throw new IdTokenException('The token response carries no id_token.');

        $userInfo = array_intersect($scopes, self::USERINFO_SCOPES) !== []
            ? $oidc->userInfo($tokens->accessToken, $idToken->subject)
            : [];

        return $this->user = $this->member($idToken, $userInfo, $tokens, $scopes);
    }

    /**
     * Adds scopes to the ones requested.
     *
     * @param list<string>|string $scopes
     */
    public function scopes(array|string $scopes): static
    {
        $this->scopes = array_values(array_unique([...$this->scopes, ...(array) $scopes]));

        return $this;
    }

    /**
     * Replaces the scopes requested.
     *
     * @param list<string>|string $scopes
     */
    public function setScopes(array|string $scopes): static
    {
        $this->scopes = array_values(array_unique((array) $scopes));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Further authorization parameters, such as `prompt` or `login_hint`.
     * The ones the flow's security rests on are set by the SDK and refused
     * here.
     *
     * @param array<string, string> $parameters
     */
    public function with(array $parameters): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Seconds since the member last authenticated beyond which the provider
     * must ask again (OpenID Connect `max_age`). The callback then refuses
     * an ID token whose `auth_time` is older, as the SDK checks it.
     */
    public function maxAge(int $seconds): static
    {
        $this->maxAge = $seconds;

        return $this;
    }

    public function redirectUrl(string $url): static
    {
        $this->redirectUrl = $url;

        return $this;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Refused: state, nonce and the PKCE verifier are what make the
     * callback safe to accept, and they live in the session between the
     * two requests.
     */
    public function stateless(): never
    {
        throw new LogicException('The appsolutely driver cannot be stateless: state, nonce and the PKCE verifier are kept in the session between the redirect and the callback.');
    }

    private function redirectUri(): string
    {
        $url = $this->redirectUrl ?? $this->settings->redirectUri();

        // A path on this application, as Socialite accepts for its own drivers.
        return str_starts_with($url, '/') ? $this->urls->to($url) : $url;
    }

    /**
     * @return array{AuthorizationRequest, list<string>}
     */
    private function pendingSignIn(): array
    {
        $pending = $this->request->session()->pull(self::SESSION_KEY);
        $maxAge = is_array($pending) ? $pending['max_age'] ?? null : null;
        if (
            !is_array($pending)
            || !is_string($pending['url'] ?? null)
            || !is_string($pending['redirect_uri'] ?? null)
            || !is_string($pending['state'] ?? null)
            || !is_string($pending['nonce'] ?? null)
            || !is_string($pending['code_verifier'] ?? null)
            || !(is_int($maxAge) || $maxAge === null)
            || !is_array($pending['scopes'] ?? null)
        ) {
            throw new InvalidStateException('No Appsolutely sign-in is in progress in this session.');
        }

        return [
            new AuthorizationRequest($pending['url'], $pending['redirect_uri'], $pending['state'], $pending['nonce'], $pending['code_verifier'], $maxAge),
            array_values(array_filter($pending['scopes'], is_string(...))),
        ];
    }

    /**
     * @param array<array-key, mixed> $userInfo
     * @param list<string> $requested
     */
    private function member(IdToken $idToken, array $userInfo, TokenSet $tokens, array $requested): AppsolutelyUser
    {
        $claims = [...$idToken->claims, ...$userInfo];

        $user = new AppsolutelyUser($tokens, $idToken);
        $user->setRaw($claims);
        $user->map([
            'id' => $idToken->subject,
            'nickname' => self::string($claims, 'preferred_username') ?? self::string($claims, 'nickname'),
            'name' => self::string($claims, 'name'),
            'email' => self::verifiedEmail(array_key_exists('email', $userInfo) ? $userInfo : $idToken->claims),
            'avatar' => self::string($claims, 'picture'),
        ]);

        // The refresh token and the lifetime stay null when the provider
        // sent none, as on Socialite's own users.
        $user->setToken($tokens->accessToken);
        if ($tokens->refreshToken !== null) {
            $user->setRefreshToken($tokens->refreshToken);
        }
        if ($tokens->expiresAt !== null) {
            $user->setExpiresIn($tokens->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp());
        }
        $user->setApprovedScopes($tokens->scope === null ? $requested : array_values(array_filter(explode(' ', $tokens->scope), static fn(string $scope): bool => $scope !== '')));

        return $user;
    }

    /**
     * An address the provider has not verified is not the member's as far
     * as this application can tell: linking an account on it would let
     * whoever typed it take that account over. The address and its
     * `email_verified` are read from the same claim set, so one source's
     * flag never vouches for the other's address.
     *
     * @param array<array-key, mixed> $claims
     */
    private static function verifiedEmail(array $claims): ?string
    {
        return ($claims['email_verified'] ?? null) === true ? self::string($claims, 'email') : null;
    }

    /**
     * @param array<array-key, mixed> $claims
     */
    private static function string(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

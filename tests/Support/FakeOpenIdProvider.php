<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * An Appsolutely sign-in host answered through Http::fake(), shaped like
 * aio's discovery document, key set and token endpoint, so the tests go
 * through the same Laravel HTTP client the bridge hands the SDK.
 */
final class FakeOpenIdProvider
{
    public const string ISSUER = 'https://login.example.com';
    public const string CLIENT_ID = 'client-123';
    public const string CLIENT_SECRET = 's3cret/with+chars';
    public const string DISCOVERY_URL = self::ISSUER . '/.well-known/openid-configuration';
    public const string TOKEN_URL = self::ISSUER . '/oauth/token';
    public const string USERINFO_URL = self::ISSUER . '/oauth/userinfo';
    public const string JWKS_URL = self::ISSUER . '/oauth/jwks.json';
    public const string REVOCATION_URL = self::ISSUER . '/oauth/revoke';

    public readonly SigningKey $rsa;

    /** @var array<string, mixed> */
    public array $discovery;

    /** @var list<array<string, string>> */
    public array $jwks;

    /** @var (Closure(Request): PromiseInterface)|null */
    public ?Closure $token = null;

    /** @var (Closure(Request): PromiseInterface)|null */
    public ?Closure $userInfo = null;

    public function __construct()
    {
        $this->rsa = SigningKey::rsa('rsa-1');
        $this->jwks = [$this->rsa->jwk];
        $this->discovery = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/oauth/authorize',
            'token_endpoint' => self::TOKEN_URL,
            'userinfo_endpoint' => self::USERINFO_URL,
            'jwks_uri' => self::JWKS_URL,
            'revocation_endpoint' => self::REVOCATION_URL,
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'authorization_response_iss_parameter_supported' => true,
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    public function fake(): self
    {
        Http::fake(fn(Request $request): PromiseInterface => $this->handle($request));

        return $this;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function claims(array $overrides = []): array
    {
        $now = Date::now()->getTimestamp();

        return array_merge([
            'iss' => self::ISSUER,
            'sub' => 'member-42',
            'aud' => self::CLIENT_ID,
            'exp' => $now + 300,
            'iat' => $now,
            'auth_time' => $now,
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    public static function form(Request $request): array
    {
        parse_str($request->body(), $fields);

        $form = [];
        foreach ($fields as $name => $value) {
            if (is_string($value)) {
                $form[(string) $name] = $value;
            }
        }

        return $form;
    }

    /**
     * @return list<Request>
     */
    public static function requestsTo(string $url): array
    {
        return array_values(Http::recorded(static fn(Request $request): bool => $request->url() === $url)
            ->map(static fn(array $pair): Request => $pair[0])
            ->all());
    }

    private function handle(Request $request): PromiseInterface
    {
        $url = $request->url();

        if ($request->method() === 'GET' && $url === self::DISCOVERY_URL) {
            return Http::response($this->discovery, 200, ['Cache-Control' => 'public, max-age=600']);
        }
        if ($request->method() === 'GET' && $url === self::JWKS_URL) {
            return Http::response(['keys' => $this->jwks], 200, ['Cache-Control' => 'public, max-age=3600']);
        }
        if ($request->method() === 'POST' && $url === self::TOKEN_URL && $this->token !== null) {
            return ($this->token)($request);
        }
        if ($request->method() === 'POST' && $url === self::REVOCATION_URL) {
            return Http::response('', 200);
        }
        if ($request->method() === 'GET' && $url === self::USERINFO_URL && $this->userInfo !== null) {
            return ($this->userInfo)($request);
        }

        return Http::response(['error' => 'not_found'], 404);
    }
}

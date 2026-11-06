<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel;

use Appsolutely\Sdk\Api\RetryPolicy;
use Appsolutely\Sdk\Clock\Sleeper;
use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Exception\InvalidConfigException;
use Appsolutely\Sdk\Oidc\ClientAuthentication;
use Illuminate\Contracts\Config\Repository;

/**
 * The `appsolutely` configuration, read and checked where it is used rather
 * than at boot, so an application that has not configured the package yet
 * still boots and the error names the environment variable to set.
 *
 * @internal
 */
final readonly class Settings
{
    public function __construct(
        private Repository $config,
    ) {}

    public function baseUrl(): string
    {
        return $this->requiredString(EnvironmentVariable::BaseUrl);
    }

    /**
     * Needed only to sign members in and to call as the backend; an
     * application doing neither may leave it unset.
     */
    public function issuer(): ?string
    {
        $issuer = $this->nullableString(EnvironmentVariable::Issuer);
        if ($issuer === null && ($this->signsMembersIn() || $this->backendClientId() !== null)) {
            throw new InvalidConfigException(sprintf(
                'The Appsolutely setting appsolutely.%s is not set; set %s or edit config/appsolutely.php: signing members in and calling as the backend need it.',
                EnvironmentVariable::Issuer->configKey(),
                EnvironmentVariable::Issuer->value,
            ));
        }

        return $issuer;
    }

    public function clientId(): ?string
    {
        return $this->memberClient()[0];
    }

    public function clientSecret(): ?string
    {
        return $this->memberClient()[1];
    }

    /**
     * Whether the member sign-in client is configured; an application that
     * never signs members in leaves its id and secret unset.
     */
    public function signsMembersIn(): bool
    {
        return $this->memberClient()[0] !== null;
    }

    /**
     * Refuses, naming every unset variable, unless the issuer and the
     * member sign-in client are set: called where members are signed in, so
     * an application that never does so is not asked for them.
     */
    public function assertSignsMembersIn(string $purpose): void
    {
        $unset = array_values(array_filter(
            [EnvironmentVariable::Issuer, EnvironmentVariable::ClientId, EnvironmentVariable::ClientSecret],
            fn(EnvironmentVariable $variable): bool => $this->nullableString($variable) === null,
        ));
        if ($unset !== []) {
            throw new InvalidConfigException(sprintf(
                '%s and needs %s set; set them or edit config/appsolutely.php.',
                $purpose,
                implode(', ', array_map(static fn(EnvironmentVariable $variable): string => $variable->value, $unset)),
            ));
        }
    }

    public function backendClientId(): ?string
    {
        return $this->nullableString(EnvironmentVariable::BackendClientId);
    }

    public function backendClientSecret(): ?string
    {
        return $this->nullableString(EnvironmentVariable::BackendClientSecret);
    }

    /**
     * @return list<string>
     */
    public function backendScopes(): array
    {
        return $this->spaceSeparated(EnvironmentVariable::BackendScopes);
    }

    /**
     * @return list<string>
     */
    private function spaceSeparated(EnvironmentVariable $variable): array
    {
        $scopes = $this->nullableString($variable);
        if ($scopes === null) {
            return [];
        }

        $split = preg_split('/\s+/', $scopes, -1, PREG_SPLIT_NO_EMPTY);

        return $split === false ? [] : $split;
    }

    public function hasLegacyApiToken(): bool
    {
        $value = $this->config->get('appsolutely.api_token');

        return $value !== null && $value !== '' && $value !== false;
    }

    public function adminClientConfigured(): bool
    {
        $id = $this->nullableString(EnvironmentVariable::AdminClientId);
        $secret = $this->nullableString(EnvironmentVariable::AdminClientSecret);
        if (($id === null) !== ($secret === null)) {
            throw self::notSet($id === null ? EnvironmentVariable::AdminClientId : EnvironmentVariable::AdminClientSecret);
        }

        return $id !== null;
    }

    /**
     * The administrator credential, refused naming every unset variable:
     * asked for only where the Administrator API is called, so an
     * application that never calls it is not asked for one.
     *
     * @return array{string, string, string, non-empty-list<string>} the issuer, the client id, its secret and its scopes
     */
    public function adminCredential(): array
    {
        $issuer = $this->nullableString(EnvironmentVariable::Issuer);
        $clientId = $this->nullableString(EnvironmentVariable::AdminClientId);
        $clientSecret = $this->nullableString(EnvironmentVariable::AdminClientSecret);
        $scopes = $this->spaceSeparated(EnvironmentVariable::AdminScopes);
        if ($issuer === null || $clientId === null || $clientSecret === null || $scopes === []) {
            $unset = array_keys(array_filter([
                EnvironmentVariable::Issuer->value => $issuer === null,
                EnvironmentVariable::AdminClientId->value => $clientId === null,
                EnvironmentVariable::AdminClientSecret->value => $clientSecret === null,
                EnvironmentVariable::AdminScopes->value => $scopes === [],
            ]));

            throw new InvalidConfigException(sprintf(
                'The Administrator API is called with an administrator credential and needs %s set; set them or edit config/appsolutely.php.',
                implode(', ', $unset),
            ));
        }

        return [$issuer, $clientId, $clientSecret, $scopes];
    }

    public function clientAuthentication(): ClientAuthentication
    {
        return $this->authentication(EnvironmentVariable::ClientAuthentication)
            ?? throw self::unknownAuthentication(EnvironmentVariable::ClientAuthentication);
    }

    /**
     * Null when unset, for the SDK to authenticate the backend as the
     * member sign-in client does.
     */
    public function backendClientAuthentication(): ?ClientAuthentication
    {
        $variable = EnvironmentVariable::BackendClientAuthentication;
        if ($this->nullableString($variable) === null) {
            return null;
        }

        return $this->authentication($variable) ?? throw self::unknownAuthentication($variable);
    }

    public function adminClientAuthentication(): ClientAuthentication
    {
        $variable = EnvironmentVariable::AdminClientAuthentication;
        if ($this->nullableString($variable) === null) {
            return $this->clientAuthentication();
        }

        return $this->authentication($variable) ?? throw self::unknownAuthentication($variable);
    }

    public function clockLeeway(): int
    {
        $leeway = $this->integer(EnvironmentVariable::ClockLeeway);
        if ($leeway > Config::MAX_CLOCK_LEEWAY) {
            throw self::invalid(EnvironmentVariable::ClockLeeway, sprintf('at most %d seconds', Config::MAX_CLOCK_LEEWAY));
        }

        return $leeway;
    }

    /**
     * Seconds a request to the site or its issuer may take, end to end.
     * The SDK's Config refuses the same range; checked here first so the
     * error names the variable.
     */
    public function timeout(): float
    {
        $timeout = $this->seconds(EnvironmentVariable::HttpTimeout);
        if ($timeout <= 0 || $timeout > Config::MAX_TIMEOUT) {
            throw self::invalid(EnvironmentVariable::HttpTimeout, sprintf('above zero and at most %d seconds', (int) Config::MAX_TIMEOUT));
        }

        return $timeout;
    }

    /** Seconds to establish a connection, TLS included; at most the request timeout. */
    public function connectTimeout(): float
    {
        $connectTimeout = $this->seconds(EnvironmentVariable::HttpConnectTimeout);
        if ($connectTimeout <= 0 || $connectTimeout > $this->timeout()) {
            throw self::invalid(EnvironmentVariable::HttpConnectTimeout, sprintf('above zero and at most the request timeout (%s)', EnvironmentVariable::HttpTimeout->value));
        }

        return $connectTimeout;
    }

    /**
     * The SDK's retries of Site API calls that may safely be repeated. Each
     * value is checked against the SDK's RetryPolicy bounds first, so the
     * error names the one variable to fix.
     */
    public function retryPolicy(Sleeper $sleeper): RetryPolicy
    {
        $maxRetries = $this->integer(EnvironmentVariable::HttpRetries, 'a whole number of retries');
        if ($maxRetries > RetryPolicy::MAX_RETRIES) {
            throw self::invalid(EnvironmentVariable::HttpRetries, sprintf('at most %d', RetryPolicy::MAX_RETRIES));
        }

        $maxDelay = $this->integer(EnvironmentVariable::HttpRetryMaxDelay);
        if ($maxDelay < 1 || $maxDelay > RetryPolicy::MAX_DELAY) {
            throw self::invalid(EnvironmentVariable::HttpRetryMaxDelay, sprintf('between 1 and %d seconds', RetryPolicy::MAX_DELAY));
        }

        $baseDelay = $this->seconds(EnvironmentVariable::HttpRetryBaseDelay);
        if ($baseDelay <= 0 || $baseDelay > $maxDelay) {
            throw self::invalid(EnvironmentVariable::HttpRetryBaseDelay, sprintf('above zero and at most the longest wait, %d seconds', $maxDelay));
        }

        return new RetryPolicy(maxRetries: $maxRetries, baseDelay: $baseDelay, maxDelay: $maxDelay, sleeper: $sleeper);
    }

    public function cacheStore(): ?string
    {
        return $this->nullableString(EnvironmentVariable::CacheStore);
    }

    public function redirectUri(): string
    {
        return $this->requiredString(EnvironmentVariable::RedirectUri);
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        $value = $this->config->get('appsolutely.socialite.scopes');
        if (is_string($value)) {
            $value = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidConfigException('The Appsolutely setting appsolutely.socialite.scopes must be a list of scopes.');
        }

        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new InvalidConfigException('The Appsolutely setting appsolutely.socialite.scopes must be a list of scopes.');
            }
            $scopes[] = $scope;
        }

        return $scopes;
    }

    /**
     * Empty entries are dropped, so a trailing comma or the spaces after
     * one in the environment variable do not count as a secret; none left
     * is a configuration error naming the variable, not a refused delivery.
     *
     * @return non-empty-list<string>
     */
    public function webhookSecrets(): array
    {
        $variable = EnvironmentVariable::WebhookSecrets;
        $value = $this->get($variable);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw self::invalid($variable, 'a comma-separated string or a list of secrets');
        }

        $secrets = [];
        foreach ($value as $secret) {
            if (!is_string($secret)) {
                throw self::invalid($variable, 'a comma-separated string or a list of secrets');
            }
            if (trim($secret) !== '') {
                $secrets[] = trim($secret);
            }
        }

        if ($secrets === []) {
            throw self::notSet($variable);
        }

        return $secrets;
    }

    public function webhookTolerance(): int
    {
        return $this->integer(EnvironmentVariable::WebhookTolerance);
    }

    public function webhookPath(): string
    {
        return $this->requiredString(EnvironmentVariable::WebhookPath);
    }

    public function deliveriesNamespace(): string
    {
        return $this->nullableString(EnvironmentVariable::WebhookDeliveriesNamespace) ?? '';
    }

    private function authentication(EnvironmentVariable $variable): ?ClientAuthentication
    {
        $value = $this->get($variable);

        return is_string($value) ? ClientAuthentication::tryFrom($value) : null;
    }

    private static function unknownAuthentication(EnvironmentVariable $variable): InvalidConfigException
    {
        return new InvalidConfigException(sprintf(
            'The Appsolutely client authentication (%s, appsolutely.%s) must be one of %s.',
            $variable->value,
            $variable->configKey(),
            implode(', ', array_map(static fn(ClientAuthentication $case): string => $case->value, ClientAuthentication::cases())),
        ));
    }

    /**
     * @return array{string, string}|array{null, null}
     */
    private function memberClient(): array
    {
        $id = $this->nullableString(EnvironmentVariable::ClientId);
        $secret = $this->nullableString(EnvironmentVariable::ClientSecret);
        if (($id === null) !== ($secret === null)) {
            throw new InvalidConfigException(sprintf(
                'The Appsolutely settings appsolutely.%s (%s) and appsolutely.%s (%s) go together: set both to sign members in, or neither.',
                EnvironmentVariable::ClientId->configKey(),
                EnvironmentVariable::ClientId->value,
                EnvironmentVariable::ClientSecret->configKey(),
                EnvironmentVariable::ClientSecret->value,
            ));
        }

        return $id === null || $secret === null ? [null, null] : [$id, $secret];
    }

    private function get(EnvironmentVariable $variable): mixed
    {
        return $this->config->get('appsolutely.' . $variable->configKey());
    }

    private function requiredString(EnvironmentVariable $variable): string
    {
        return $this->nullableString($variable) ?? throw self::notSet($variable);
    }

    private static function notSet(EnvironmentVariable $variable): InvalidConfigException
    {
        return new InvalidConfigException(sprintf(
            'The Appsolutely setting appsolutely.%s is not set; set %s or edit config/appsolutely.php.',
            $variable->configKey(),
            $variable->value,
        ));
    }

    private static function invalid(EnvironmentVariable $variable, string $expected): InvalidConfigException
    {
        return new InvalidConfigException(sprintf(
            'The Appsolutely setting appsolutely.%s (%s) must be %s.',
            $variable->configKey(),
            $variable->value,
            $expected,
        ));
    }

    private function nullableString(EnvironmentVariable $variable): ?string
    {
        $value = $this->get($variable);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalid($variable, 'a string');
        }

        return $value;
    }

    /**
     * An environment variable arrives as a string; anything but whole
     * decimal digits is refused rather than cast to 0. An integer written in
     * the configuration file is held to the same rule: none of these
     * settings may be negative.
     */
    private function integer(EnvironmentVariable $variable, string $expected = 'a whole number of seconds'): int
    {
        $value = $this->get($variable);
        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        throw self::invalid($variable, $expected);
    }

    /**
     * Decimal digits with an optional fraction, as an environment variable
     * holds them; a sign or anything else is refused rather than cast.
     */
    private function seconds(EnvironmentVariable $variable): float
    {
        $value = $this->get($variable);
        if (is_string($value) && preg_match('/^\d{1,6}(\.\d{1,6})?$/', $value) === 1) {
            return (float) $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw self::invalid($variable, 'a number of seconds');
    }
}

<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel;

/**
 * The environment variables `config/appsolutely.php` reads, each with the
 * setting it fills, so every error message that tells the reader what to
 * set uses the configuration file's spelling.
 *
 * The configuration file writes each name, and each default, as a literal
 * rather than through this enum or the SDK's constants: a published copy
 * must not depend on a class of this package or of sdk-php, which may be
 * given another name under it. Tests keep the names and the defaults equal.
 *
 * @internal
 */
enum EnvironmentVariable: string
{
    case BaseUrl = 'APPSOLUTELY_BASE_URL';
    case Issuer = 'APPSOLUTELY_ISSUER';
    case ClientId = 'APPSOLUTELY_CLIENT_ID';
    case ClientSecret = 'APPSOLUTELY_CLIENT_SECRET';
    case BackendClientId = 'APPSOLUTELY_BACKEND_CLIENT_ID';
    case BackendClientSecret = 'APPSOLUTELY_BACKEND_CLIENT_SECRET';
    case BackendScopes = 'APPSOLUTELY_BACKEND_SCOPES';
    case BackendClientAuthentication = 'APPSOLUTELY_BACKEND_CLIENT_AUTHENTICATION';
    case AdminClientId = 'APPSOLUTELY_ADMIN_CLIENT_ID';
    case AdminClientSecret = 'APPSOLUTELY_ADMIN_CLIENT_SECRET';
    case AdminScopes = 'APPSOLUTELY_ADMIN_SCOPES';
    case AdminClientAuthentication = 'APPSOLUTELY_ADMIN_CLIENT_AUTHENTICATION';
    case ClientAuthentication = 'APPSOLUTELY_CLIENT_AUTHENTICATION';
    case ClockLeeway = 'APPSOLUTELY_CLOCK_LEEWAY';
    case CacheStore = 'APPSOLUTELY_CACHE_STORE';
    case HttpTimeout = 'APPSOLUTELY_HTTP_TIMEOUT';
    case HttpConnectTimeout = 'APPSOLUTELY_HTTP_CONNECT_TIMEOUT';
    case HttpRetries = 'APPSOLUTELY_HTTP_RETRIES';
    case HttpRetryBaseDelay = 'APPSOLUTELY_HTTP_RETRY_BASE_DELAY';
    case HttpRetryMaxDelay = 'APPSOLUTELY_HTTP_RETRY_MAX_DELAY';
    case RedirectUri = 'APPSOLUTELY_REDIRECT_URI';
    case WebhookSecrets = 'APPSOLUTELY_WEBHOOK_SECRETS';
    case WebhookTolerance = 'APPSOLUTELY_WEBHOOK_TOLERANCE';
    case WebhookPath = 'APPSOLUTELY_WEBHOOK_PATH';
    case WebhookDeliveriesNamespace = 'APPSOLUTELY_WEBHOOK_DELIVERIES_NAMESPACE';

    /**
     * The setting under `appsolutely.` the variable fills, in dot notation.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::BaseUrl => 'base_url',
            self::Issuer => 'issuer',
            self::ClientId => 'client_id',
            self::ClientSecret => 'client_secret',
            self::BackendClientId => 'backend.client_id',
            self::BackendClientSecret => 'backend.client_secret',
            self::BackendScopes => 'backend.scopes',
            self::BackendClientAuthentication => 'backend.client_authentication',
            self::AdminClientId => 'admin.client_id',
            self::AdminClientSecret => 'admin.client_secret',
            self::AdminScopes => 'admin.scopes',
            self::AdminClientAuthentication => 'admin.client_authentication',
            self::ClientAuthentication => 'client_authentication',
            self::ClockLeeway => 'clock_leeway',
            self::CacheStore => 'cache_store',
            self::HttpTimeout => 'http.timeout',
            self::HttpConnectTimeout => 'http.connect_timeout',
            self::HttpRetries => 'http.retries.max',
            self::HttpRetryBaseDelay => 'http.retries.base_delay',
            self::HttpRetryMaxDelay => 'http.retries.max_delay',
            self::RedirectUri => 'socialite.redirect',
            self::WebhookSecrets => 'webhooks.secrets',
            self::WebhookTolerance => 'webhooks.tolerance',
            self::WebhookPath => 'webhooks.path',
            self::WebhookDeliveriesNamespace => 'webhooks.deliveries_namespace',
        };
    }
}

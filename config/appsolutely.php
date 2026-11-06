<?php

declare(strict_types=1);

return [
    /*
     * The site's origin, such as `https://shop.example.com`, without a path:
     * every Site API path is joined to it.
     */
    'base_url' => env('APPSOLUTELY_BASE_URL'),

    /*
     * The site's sign-in host, exactly as its discovery document names it:
     * OpenID Connect compares it to every ID token's `iss` as a plain string.
     * Needed to sign members in and to call as the backend.
     */
    'issuer' => env('APPSOLUTELY_ISSUER'),

    /*
     * The client that signs members in, with its secret: set both, or
     * neither when the application never signs members in.
     */
    'client_id' => env('APPSOLUTELY_CLIENT_ID'),

    'client_secret' => env('APPSOLUTELY_CLIENT_SECRET'),

    /*
     * How the client authenticates to the token and revocation endpoints:
     * `client_secret_basic` or `client_secret_post`.
     */
    'client_authentication' => env('APPSOLUTELY_CLIENT_AUTHENTICATION', 'client_secret_basic'),

    /*
     * Seconds of clock disagreement tolerated when checking an ID token's
     * exp, iat and nbf, and its auth_time against a requested max age.
     */
    'clock_leeway' => env('APPSOLUTELY_CLOCK_LEEWAY', 60),

    /*
     * The cache store that keeps the discovery document, the signing keys
     * and the handled webhook ids; null for the default store. Use one that
     * outlives a request (not `array`), or every request fetches discovery
     * again and a retried delivery is handled twice.
     */
    'cache_store' => env('APPSOLUTELY_CACHE_STORE'),

    'http' => [
        /*
         * Seconds a request to the site or its issuer may take, end to end,
         * and seconds to establish its connection, TLS included.
         */
        'timeout' => env('APPSOLUTELY_HTTP_TIMEOUT', 30.0),

        'connect_timeout' => env('APPSOLUTELY_HTTP_CONNECT_TIMEOUT', 10.0),

        /*
         * Site API calls that may safely be repeated (reads, and writes sent
         * with an idempotency key) are retried after a network failure or a
         * passing refusal: at most `max` times (0 turns retrying off), after
         * a backoff that doubles from `base_delay` seconds, never waiting
         * longer than `max_delay` seconds at once.
         */
        'retries' => [
            'max' => env('APPSOLUTELY_HTTP_RETRIES', 2),

            'base_delay' => env('APPSOLUTELY_HTTP_RETRY_BASE_DELAY', 0.5),

            'max_delay' => env('APPSOLUTELY_HTTP_RETRY_MAX_DELAY', 30),
        ],
    ],

    'socialite' => [
        /*
         * Where the provider sends the member back: an absolute URL, or a
         * path on this application. It must be registered for the client.
         */
        'redirect' => env('APPSOLUTELY_REDIRECT_URI'),

        /*
         * Requested on every sign-in unless the driver is told otherwise
         * with scopes() or setScopes(). `openid` is always added.
         */
        'scopes' => ['openid', 'email', 'profile'],
    ],

    'backend' => [
        /*
         * The machine client registered for this application's backend,
         * apart from the client that signs members in: Appsolutely::backend()
         * calls the site with a token it is granted. Set both, or neither.
         */
        'client_id' => env('APPSOLUTELY_BACKEND_CLIENT_ID'),

        'client_secret' => env('APPSOLUTELY_BACKEND_CLIENT_SECRET'),

        /*
         * The scopes to ask the backend's token for, space-separated; empty
         * asks for every scope the machine client is registered with.
         */
        'scopes' => env('APPSOLUTELY_BACKEND_SCOPES', ''),

        /*
         * How the machine client authenticates to the token endpoint:
         * `client_secret_basic` or `client_secret_post`; unset, as
         * `client_authentication` above says.
         */
        'client_authentication' => env('APPSOLUTELY_BACKEND_CLIENT_AUTHENTICATION'),
    ],

    'admin' => [
        /*
         * An administrator credential: the OAuth client an administrator
         * issued on the site for the Administrator API, which the container's
         * Admin\AdminClient calls with. Set it with its secret and scopes, or
         * leave all three unset.
         */
        'client_id' => env('APPSOLUTELY_ADMIN_CLIENT_ID'),

        'client_secret' => env('APPSOLUTELY_ADMIN_CLIENT_SECRET'),

        /*
         * The Administrator API scopes to ask its token for, space-separated,
         * such as `admin:articles:read admin:articles:write`; at least one.
         */
        'scopes' => env('APPSOLUTELY_ADMIN_SCOPES', ''),

        /*
         * How the administrator client authenticates: `client_secret_basic`
         * or `client_secret_post`; unset, as `client_authentication` above says.
         */
        'client_authentication' => env('APPSOLUTELY_ADMIN_CLIENT_AUTHENTICATION'),
    ],

    'webhooks' => [
        /*
         * The endpoint's signing secrets (`whsec_...`), comma-separated:
         * one, or during a rotation the new one and the old one.
         */
        'secrets' => env('APPSOLUTELY_WEBHOOK_SECRETS', ''),

        /*
         * Seconds a delivery's timestamp may differ from this host's clock
         * before it is refused as a possible replay.
         */
        'tolerance' => env('APPSOLUTELY_WEBHOOK_TOLERANCE', 300),

        /* Where Route::appsolutelyWebhooks() listens when given no path. */
        'path' => env('APPSOLUTELY_WEBHOOK_PATH', 'appsolutely/webhooks'),

        /*
         * Keeps this endpoint's record of handled deliveries apart from
         * another endpoint's in the same cache store; set a distinct value
         * when two applications or endpoints share one.
         */
        'deliveries_namespace' => env('APPSOLUTELY_WEBHOOK_DELIVERIES_NAMESPACE', ''),
    ],
];

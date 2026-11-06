<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Webhooks;

use Appsolutely\Sdk\Laravel\Settings;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * What Route::appsolutelyWebhooks() registers.
 *
 * @internal
 */
final readonly class WebhookRoute
{
    public const string NAME = 'appsolutely.webhooks';

    /**
     * The sender signs its deliveries and carries no CSRF token, so the
     * route opts out of CSRF protection itself rather than asking every
     * application to list its path in an exception. Laravel 13 checks
     * requests with PreventRequestForgery and Laravel 12 with
     * VerifyCsrfToken; ValidateCsrfToken extends one or the other, and the
     * router also excludes subclasses. Named as strings because only one
     * of them exists on a given version.
     */
    private const array CSRF_MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ];

    public function __construct(
        private Router $router,
        private Container $container,
    ) {}

    public function register(?string $uri = null): Route
    {
        return $this->router
            ->post($uri ?? $this->container->make(Settings::class)->webhookPath(), HandleWebhook::class)
            ->middleware(VerifyWebhookSignature::class)
            ->withoutMiddleware(self::CSRF_MIDDLEWARE)
            ->name(self::NAME);
    }
}

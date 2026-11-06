<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Testing;

use Appsolutely\Sdk\Laravel\Clock\LaravelClock;
use Appsolutely\Sdk\Laravel\Settings;
use Appsolutely\Sdk\Laravel\Webhooks\WebhookRoute;
use Appsolutely\Sdk\Testing\SignedWebhook;
use Appsolutely\Sdk\Testing\WebhookFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\DateFactory;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

// illuminate/testing is a suggestion, not a requirement: only this trait
// needs it, and a production install may leave it out.
if (!class_exists(TestResponse::class)) {
    throw new LogicException('InteractsWithAppsolutelyWebhooks sends deliveries with Laravel\'s testing helpers; require illuminate/testing as a development dependency (laravel/framework includes it).');
}

/**
 * For a Laravel feature test: sends deliveries signed exactly as
 * Appsolutely signs them, with the configured secrets and Laravel's clock,
 * to the route Route::appsolutelyWebhooks() registered. A test helper: not
 * for production code.
 *
 *     $this->postAppsolutelyWebhook(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1'])->assertOk();
 *
 * with Appsolutely\Sdk\Webhooks\EventType naming the type.
 */
trait InteractsWithAppsolutelyWebhooks
{
    /**
     * @param array<string, mixed> $data
     * @param string $mode `production` or `test`, as the server writes it
     * @param string|null $uri the endpoint; the route Route::appsolutelyWebhooks() registered by default
     * @return TestResponse<Response>
     */
    protected function postAppsolutelyWebhook(string $type, array $data = [], string $mode = 'production', ?string $id = null, ?string $uri = null): TestResponse
    {
        return $this->sendAppsolutelyWebhook($this->appsolutelyWebhookFactory()->make($type, $data, $mode, $id), $uri);
    }

    /**
     * Sends a delivery as it is, to send one twice or to alter it first.
     *
     * @return TestResponse<Response>
     */
    protected function sendAppsolutelyWebhook(SignedWebhook $delivery, ?string $uri = null): TestResponse
    {
        return $this->call(
            'POST',
            $uri ?? $this->appsolutelyContainer()->make(UrlGenerator::class)->route(WebhookRoute::NAME),
            server: $this->transformHeadersToServerVars([...$delivery->headers, 'Content-Type' => 'application/json']),
            content: $delivery->body,
        );
    }

    /**
     * Signs with the configured secrets on Laravel's clock, so a test that
     * travels in time sends deliveries the endpoint finds current.
     */
    protected function appsolutelyWebhookFactory(): WebhookFactory
    {
        return new WebhookFactory(
            $this->appsolutelyContainer()->make(Settings::class)->webhookSecrets(),
            new LaravelClock($this->appsolutelyContainer()->make(DateFactory::class)),
        );
    }

    private function appsolutelyContainer(): Container
    {
        return $this->app ?? throw new LogicException('The application is not created yet; send deliveries from a test method.');
    }
}

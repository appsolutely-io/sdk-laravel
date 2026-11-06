<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel;

use Appsolutely\Sdk\Admin\AdminClient;
use Appsolutely\Sdk\Api\PublicApi;
use Appsolutely\Sdk\Cache\TokenLock;
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Config;
use Appsolutely\Sdk\Laravel\Cache\LaravelTokenLock;
use Appsolutely\Sdk\Laravel\Clock\LaravelClock;
use Appsolutely\Sdk\Laravel\Console\CheckCommand;
use Appsolutely\Sdk\Laravel\Socialite\AppsolutelyProvider;
use Appsolutely\Sdk\Laravel\Webhooks\DeliveryLock;
use Appsolutely\Sdk\Laravel\Webhooks\WebhookRoute;
use Appsolutely\Sdk\Webhooks\Deliveries;
use Appsolutely\Sdk\Webhooks\Verifier;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\DateFactory;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\SocialiteManager;

final class AppsolutelyServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__ . '/../config/appsolutely.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'appsolutely');

        $this->app->bind(Settings::class, static fn(Container $app): Settings => new Settings($app->make(Repository::class)));

        // In the store that keeps the backend's token, so every process
        // sharing the token shares the lock around replacing it.
        $this->app->bind(TokenLock::class, static fn(Container $app): TokenLock => new LaravelTokenLock(
            $app->make(CacheFactory::class)->store($app->make(Settings::class)->cacheStore()),
        ));

        // Built on first use, so an application that has not configured
        // the package yet still boots; the error then names what to set.
        $this->app->singleton(Client::class, static fn(Container $app): Client => $app->make(ClientFactory::class)->make(
            $app->make(CacheFactory::class)->store($app->make(Settings::class)->cacheStore()),
            $app->make(TokenLock::class),
        ));

        // Built on first use like the client, and only where the
        // Administrator API is called, so the credential is asked for there.
        $this->app->singleton(AdminClient::class, static fn(Container $app): AdminClient => $app->make(ClientFactory::class)->makeAdmin(
            $app->make(CacheFactory::class)->store($app->make(Settings::class)->cacheStore()),
            $app->make(TokenLock::class),
        ));

        // The client's own instance of the calls anyone may make, for
        // constructor injection; the SDK builds it, the container cannot.
        $this->app->bind(PublicApi::class, static fn(Container $app): PublicApi => $app->make(Client::class)->api());

        // Built per request from the settings, so a missing secret is an
        // error on the first delivery, reported as a 500, not at boot.
        $this->app->bind(Verifier::class, static function (Container $app): Verifier {
            $settings = $app->make(Settings::class);

            return new Verifier($settings->webhookSecrets(), new LaravelClock($app->make(DateFactory::class)), $settings->webhookTolerance());
        });

        $this->app->bind(DeliveryLock::class, static function (Container $app): DeliveryLock {
            $settings = $app->make(Settings::class);

            return new DeliveryLock(
                $app->make(CacheFactory::class)->store($settings->cacheStore()),
                $settings->deliveriesNamespace(),
            );
        });

        $this->app->bind(Deliveries::class, static function (Container $app): Deliveries {
            $settings = $app->make(Settings::class);

            return new Deliveries(
                $app->make(CacheFactory::class)->store($settings->cacheStore()),
                namespace: $settings->deliveriesNamespace(),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('appsolutely.php')], 'appsolutely-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }

        $app = $this->app;
        // A closure that is not static: the router binds macros to itself.
        Router::macro('appsolutelyWebhooks', function (?string $uri = null) use ($app): Route {
            return $app->make(WebhookRoute::class)->register($uri);
        });

        // Registered when Socialite is first resolved (or now, if it already
        // was), so an application that never signs in does not build it.
        $this->callAfterResolving(SocialiteFactory::class, static function (SocialiteFactory $socialite): void {
            if ($socialite instanceof SocialiteManager) {
                $socialite->extend('appsolutely', function (Container $app): AppsolutelyProvider {
                    // Member sign-in is optional for the rest of the package;
                    // the driver is where it is needed, so it is checked here.
                    $settings = $app->make(Settings::class);
                    $settings->assertSignsMembersIn('The appsolutely Socialite driver signs members in');

                    return new AppsolutelyProvider(
                        $app->make(Request::class),
                        $app->make(Client::class),
                        $settings,
                        $app->make(UrlGenerator::class),
                        new LaravelClock($app->make(DateFactory::class)),
                    );
                });
            }
        });
    }
}

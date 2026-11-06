<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests;

use Appsolutely\Sdk\Laravel\AppsolutelyServiceProvider;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /** The site the client is configured for; its sign-in host is FakeOpenIdProvider::ISSUER. */
    public const string BASE_URL = 'https://shop.example.com';

    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AppsolutelyServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('appsolutely.base_url', self::BASE_URL);
        $config->set('appsolutely.issuer', FakeOpenIdProvider::ISSUER);
        $config->set('appsolutely.client_id', FakeOpenIdProvider::CLIENT_ID);
        $config->set('appsolutely.client_secret', FakeOpenIdProvider::CLIENT_SECRET);
        $config->set('cache.default', 'array');
        // The web group encrypts its session cookie.
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        // The null channel still fires MessageLogged, which the tests listen to.
        $config->set('logging.default', 'null');
    }
}

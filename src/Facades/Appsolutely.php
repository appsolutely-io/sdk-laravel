<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Facades;

use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Laravel\Clock\LaravelClock;
use Appsolutely\Sdk\Testing\FakeClient;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * @method static \Appsolutely\Sdk\Oidc\OpenIdClient oidc()
 * @method static \Appsolutely\Sdk\Api\PublicApi api()
 * @method static \Appsolutely\Sdk\Api\BackendApi backend()
 * @method static \Appsolutely\Sdk\MemberClient forMember(string $accessToken)
 *
 * @see Client
 */
final class Appsolutely extends Facade
{
    /**
     * Replaces the client, behind the facade and in the container, with the
     * SDK's FakeClient, whose Site API answers in memory what the test
     * arranges and records every call. Sign-in through oidc() is not faked.
     */
    public static function fake(): FakeClient
    {
        $app = static::getFacadeApplication() ?? throw new LogicException('Appsolutely::fake() needs a running Laravel application.');
        $fake = new FakeClient(new LaravelClock($app->make(DateFactory::class)));
        static::swap($fake->client());

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}

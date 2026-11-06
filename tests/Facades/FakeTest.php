<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Facades;

use Appsolutely\Sdk\Api\Operation;
use Appsolutely\Sdk\Api\PublicApi;
use Appsolutely\Sdk\Client;
use Appsolutely\Sdk\Exception\NotFoundException;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Testing\FakeClient;
use Illuminate\Support\Facades\Http;

final class FakeTest extends TestCase
{
    public function testTheFakeAnswersTheFacadeTheClientAndTheInjectedApi(): void
    {
        Http::preventStrayRequests();
        $fake = Appsolutely::fake()->answer(Operation::GetApiVersion, ['version' => 'v1', 'status' => 'current']);

        self::assertSame('v1', Appsolutely::api()->version()->version);
        self::assertSame($fake->client(), $this->app?->make(Client::class));
        self::assertSame($fake->api(), $this->app->make(PublicApi::class));
        self::assertCount(1, $fake->calls(Operation::GetApiVersion));
    }

    public function testBackendCallsAreRecordedWithTheFakeBackendToken(): void
    {
        $fake = Appsolutely::fake()->refuse(Operation::GetProduct, 'not-found', 404, 'The product does not exist.');

        try {
            Appsolutely::backend()->products()->get('p_1');
            self::fail('The arranged refusal was not thrown.');
        } catch (NotFoundException) {
        }

        $call = $fake->lastCall(Operation::GetProduct);
        self::assertSame(['id' => 'p_1'], $call->pathParameters);
        self::assertSame(FakeClient::BACKEND_TOKEN, $call->token);
    }

    public function testTheFakeNeedsNoConfiguration(): void
    {
        config()->set('appsolutely.base_url', null);
        config()->set('appsolutely.client_secret', null);

        Appsolutely::fake()->answer(Operation::GetApiVersion, ['version' => 'v1', 'status' => 'current']);

        self::assertSame('current', Appsolutely::api()->version()->status);
    }
}

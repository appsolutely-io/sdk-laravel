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

    public function testTheFakeAnswersMemberInboxPagesWithTheMembersCredential(): void
    {
        Http::preventStrayRequests();
        $sequence = '18446744073709551615';
        $fake = Appsolutely::fake()->answer(Operation::ListMeMessages, ['data' => [], 'sequence' => $sequence]);

        $pages = iterator_to_array(Appsolutely::forMember('member-access-token')->api()->me()->messages()->list(), false);

        self::assertCount(1, $pages);
        self::assertSame($sequence, $pages[0]->sequence);
        self::assertSame([], $pages[0]->data);
        self::assertSame('member-access-token', $fake->lastCall(Operation::ListMeMessages)->token);
        self::assertCount(1, $fake->calls(Operation::ListMeMessages));
        Http::assertNothingSent();
    }

    public function testTheFakeRecordsBackendMessageBodiesAndCallerKeys(): void
    {
        Http::preventStrayRequests();
        $fake = Appsolutely::fake()->answer(Operation::CreateBackendMemberMessage, ['id' => 'message-one', 'state' => 'queued'], 201);

        $answer = Appsolutely::backend()->memberMessages()->create('member/one', 'service', 'Ready', 'Your report is ready.', data: ['report' => 'one'], channels: ['in_app'], idempotencyKey: 'report-ready-once');

        $call = $fake->lastCall(Operation::CreateBackendMemberMessage);
        self::assertSame(FakeClient::BACKEND_TOKEN, $call->token);
        self::assertSame(['sub' => 'member/one'], $call->pathParameters);
        self::assertSame(['category' => 'service', 'title' => 'Ready', 'body' => 'Your report is ready.', 'data' => ['report' => 'one'], 'channels' => ['in_app']], $call->body);
        self::assertSame('report-ready-once', $call->idempotencyKey);
        self::assertSame('report-ready-once', $answer->idempotencyKey);
        self::assertSame('queued', $answer->value->state);
        Http::assertNothingSent();
    }

    public function testTheFakeNeedsNoConfiguration(): void
    {
        config()->set('appsolutely.base_url', null);
        config()->set('appsolutely.client_secret', null);

        Appsolutely::fake()->answer(Operation::GetApiVersion, ['version' => 'v1', 'status' => 'current']);

        self::assertSame('current', Appsolutely::api()->version()->status);
    }
}

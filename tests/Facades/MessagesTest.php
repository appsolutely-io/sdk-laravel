<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Facades;

use Appsolutely\Sdk\Exception\ApiException;
use Appsolutely\Sdk\Exception\ValidationFailedException;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Model\InboxMessagePage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class MessagesTest extends TestCase
{
    public function testAConditionalInboxReadRetainsA200TypedPageAndItsValidator(): void
    {
        Http::preventStrayRequests();
        $url = self::BASE_URL . '/api/v1/me/messages';
        $sequence = '18446744073709551615';
        Http::fake([$url . '*' => Http::response(['data' => [], 'sequence' => $sequence], 200, ['ETag' => 'W/"current"'])]);

        $answer = Appsolutely::forMember('member-access-token')->api()->me()->messages()->listConditional('W/"previous"', 10, 'page/one');

        self::assertFalse($answer->notModified());
        self::assertInstanceOf(InboxMessagePage::class, $answer->value);
        self::assertSame($sequence, $answer->value->sequence);
        self::assertSame([], $answer->value->data);
        self::assertSame('W/"current"', $answer->response->etag());
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET'
            && $request->url() === $url . '?limit=10&cursor=page%2Fone'
            && $request->hasHeader('If-None-Match', 'W/"previous"')
            && $request->hasHeader('Authorization', 'Bearer member-access-token'));
        Http::assertSentCount(1);
    }

    public function testANotModifiedInboxReadRetainsMetadataWithoutDecodingABody(): void
    {
        Http::preventStrayRequests();
        $url = self::BASE_URL . '/api/v1/me/messages';
        Http::fake([$url . '*' => Http::response('not-json', 304, ['ETag' => '"unchanged"'])]);

        $answer = Appsolutely::forMember('member-access-token')->api()->me()->messages()->listConditional('"unchanged"');

        self::assertTrue($answer->notModified());
        self::assertSame(304, $answer->response->status);
        self::assertNull($answer->value);
        self::assertNull($answer->response->data);
        self::assertSame('"unchanged"', $answer->response->etag());
        Http::assertSent(static fn(Request $request): bool => $request->hasHeader('If-None-Match', '"unchanged"'));
        Http::assertSentCount(1);
    }

    public function testABackendMessageUsesTheMachineCredentialAndPreservesItsBodyAndKey(): void
    {
        config()->set('appsolutely.backend.client_id', FakeOpenIdProvider::CLIENT_ID);
        config()->set('appsolutely.backend.client_secret', FakeOpenIdProvider::CLIENT_SECRET);
        config()->set('appsolutely.backend.scopes', 'messages:write');
        Http::preventStrayRequests();
        $url = self::BASE_URL . '/api/v1/members/member%2Fone/messages';
        Http::fake([
            FakeOpenIdProvider::DISCOVERY_URL => Http::response((new FakeOpenIdProvider())->discovery),
            FakeOpenIdProvider::TOKEN_URL => Http::response(['access_token' => 'machine-access-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            $url => Http::response(['id' => 'message-one', 'state' => 'queued'], 201),
        ]);

        $answer = Appsolutely::backend()->memberMessages()->create('member/one', 'service', 'Ready', 'Your report is ready.', data: ['report' => 'one'], channels: ['in_app'], idempotencyKey: 'report-ready-once');

        self::assertSame('report-ready-once', $answer->idempotencyKey);
        self::assertSame(201, $answer->response->status);
        self::assertSame('message-one', $answer->value->id);
        Http::assertSent(static fn(Request $request): bool => $request->url() === $url
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer machine-access-token')
            && $request->hasHeader('Idempotency-Key', '"report-ready-once"')
            && $request->data() === ['category' => 'service', 'title' => 'Ready', 'body' => 'Your report is ready.', 'data' => ['report' => 'one'], 'channels' => ['in_app']]);
        Http::assertSentCount(3);
    }

    public function testAMessagePreferenceRefusalRemainsAnApiException(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::BASE_URL . '/api/v1/me/message-preferences' => Http::response(json_encode(['type' => ApiException::TYPE_BASE . 'validation-failed', 'title' => 'Validation failed', 'status' => 422], JSON_THROW_ON_ERROR), 422, ['Content-Type' => 'application/problem+json'])]);

        $this->expectException(ValidationFailedException::class);
        Appsolutely::forMember('member-access-token')->api()->me()->messagePreferences()->update([['category' => 'security', 'channel' => 'push', 'enabled' => false]]);
    }
}

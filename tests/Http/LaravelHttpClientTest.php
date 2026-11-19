<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Http;

use Appsolutely\Sdk\Exception\ApiException;
use Appsolutely\Sdk\Exception\ValidationFailedException;
use Appsolutely\Sdk\Laravel\Facades\Appsolutely;
use Appsolutely\Sdk\Laravel\Http\LaravelHttpClient;
use Appsolutely\Sdk\Laravel\Tests\Support\FakeOpenIdProvider;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Model\InboxMessagePage;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;

final class LaravelHttpClientTest extends TestCase
{
    private const string URL = 'https://login.example.com/oauth/token';

    public function testTheRequestsContentTypeIsSentOnceWhateverTheCaseOfItsName(): void
    {
        $sent = $this->send(new PsrRequest('POST', self::URL, ['content-type' => 'application/x-www-form-urlencoded'], 'grant_type=client_credentials'));

        self::assertSame(['application/x-www-form-urlencoded'], $sent->toPsrRequest()->getHeader('Content-Type'));
        self::assertSame('grant_type=client_credentials', $sent->body());
    }

    public function testABodyWithoutAContentTypeIsSentWithoutOne(): void
    {
        $sent = $this->send(new PsrRequest('POST', self::URL, [], 'opaque'));

        self::assertFalse($sent->toPsrRequest()->hasHeader('Content-Type'));
        self::assertSame('opaque', $sent->body());
    }

    public function testARequestWithoutABodyCarriesNoContentType(): void
    {
        $sent = $this->send(new PsrRequest('GET', self::URL));

        self::assertFalse($sent->toPsrRequest()->hasHeader('Content-Type'));
    }

    public function testOtherHeadersArriveOnceWithTheirValuesWhateverTheCaseOfTheirNames(): void
    {
        $sent = $this->send(new PsrRequest('GET', self::URL, ['accept' => 'application/json', 'X-Request-Id' => ['a', 'b'], 'HOST' => 'login.example.com']));

        $psr = $sent->toPsrRequest();
        self::assertSame(['application/json'], $psr->getHeader('Accept'));
        self::assertSame(['a', 'b'], $psr->getHeader('X-Request-Id'));
        self::assertSame(['login.example.com'], $psr->getHeader('Host'));
    }

    /**
     * A header the SDK sets replaces the application's global value for that
     * header rather than joining it: a global Authorization meant for the
     * application's own services must never reach the identity provider. A
     * global header the SDK does not set still applies.
     */
    public function testTheRequestsOwnHeadersReplaceGlobalHeadersOfTheSameName(): void
    {
        Http::globalOptions(['headers' => ['Authorization' => 'Bearer app-internal', 'User-Agent' => 'app/1', 'X-App' => 'kept']]);

        $sent = $this->send(new PsrRequest('POST', self::URL, ['authorization' => 'Basic c2Rr', 'user-agent' => 'sdk/1', 'content-type' => 'application/x-www-form-urlencoded'], 'a=b'));

        $psr = $sent->toPsrRequest();
        self::assertSame(['Basic c2Rr'], $psr->getHeader('Authorization'));
        self::assertSame(['sdk/1'], $psr->getHeader('User-Agent'));
        self::assertSame(['kept'], $psr->getHeader('X-App'));
    }

    public function testAGlobalHeaderInAnotherCaseIsReplacedToo(): void
    {
        Http::globalOptions(['headers' => ['authorization' => 'Bearer app-internal', 'USER-AGENT' => 'app/1']]);

        $sent = $this->send(new PsrRequest('POST', self::URL, ['Authorization' => 'Basic c2Rr', 'User-Agent' => 'sdk/1'], 'a=b'));

        $psr = $sent->toPsrRequest();
        self::assertSame(['Basic c2Rr'], $psr->getHeader('Authorization'));
        self::assertSame(['sdk/1'], $psr->getHeader('User-Agent'));
    }

    /**
     * The SDK sets every credential its own requests need. The application's
     * global credentials belong to its own services and never reach the
     * identity provider, also on requests where the SDK sends none.
     */
    public function testGlobalCredentialsAreNotSentWhenTheRequestCarriesNone(): void
    {
        Http::globalOptions(['headers' => ['Authorization' => 'Bearer app-internal', 'Proxy-Authorization' => 'Basic cHJveHk=', 'Cookie' => 'session=app']]);

        $psr = $this->send(new PsrRequest('GET', self::URL))->toPsrRequest();

        self::assertFalse($psr->hasHeader('Authorization'));
        self::assertFalse($psr->hasHeader('Proxy-Authorization'));
        self::assertFalse($psr->hasHeader('Cookie'));
    }

    public function testHeaderNamesAreSentInTheirCanonicalCase(): void
    {
        // Laravel 12's Request::header() and hasHeader(), which Http::assertSent()
        // callbacks use, match names case-sensitively.
        $sent = $this->send(new PsrRequest('POST', self::URL, ['content-type' => 'application/json', 'ACCEPT' => 'application/json', 'x-request-ID' => 'a'], '{}'));

        self::assertTrue($sent->hasHeader('Content-Type', 'application/json'));
        self::assertTrue($sent->hasHeader('Accept', 'application/json'));
        self::assertTrue($sent->hasHeader('X-Request-Id', 'a'));
    }

    public function testAFailedConnectionIsAPsr18NetworkException(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        try {
            $this->client()->sendRequest(new PsrRequest('GET', self::URL));
            self::fail('A failed connection returned a response.');
        } catch (NetworkExceptionInterface $exception) {
            self::assertStringContainsString('Connection refused', $exception->getMessage());
            self::assertSame(self::URL, (string) $exception->getRequest()->getUri());
        }
    }

    public function testAStrayRequestIsAPsr18ClientExceptionThatWasNeverSent(): void
    {
        Http::preventStrayRequests();

        try {
            $this->client()->sendRequest(new PsrRequest('GET', self::URL));
            self::fail('A stray request returned a response.');
        } catch (ClientExceptionInterface $exception) {
            // Refused before it was sent: not a network failure.
            self::assertNotInstanceOf(NetworkExceptionInterface::class, $exception);
            self::assertInstanceOf(StrayRequestException::class, $exception->getPrevious());
            self::assertStringContainsString(self::URL, $exception->getMessage());
        }
    }

    public function testAnErrorResponseLaravelThrowsIsHandedBackAsTheResponse(): void
    {
        // A global middleware or option may make Laravel's client throw on
        // an error status; PSR-18 hands every response back to the caller.
        Http::fake(static fn(): never => throw new RequestException(new Response(new PsrResponse(503, ['Retry-After' => '5'], 'busy'))));

        $response = $this->client()->sendRequest(new PsrRequest('GET', self::URL));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
        self::assertSame('busy', (string) $response->getBody());
    }

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

    private function client(): LaravelHttpClient
    {
        $factory = $this->app?->make(Factory::class);
        self::assertInstanceOf(Factory::class, $factory);

        return new LaravelHttpClient($factory);
    }

    public function testEveryRequestCarriesTheTimeoutsItWasBuiltWith(): void
    {
        $options = [];
        Http::fake(static function (Request $request, array $sent) use (&$options): PromiseInterface {
            $options = $sent;

            return Http::response('', 200);
        });
        $factory = $this->app?->make(Factory::class);
        self::assertInstanceOf(Factory::class, $factory);

        (new LaravelHttpClient($factory, timeout: 12.5, connectTimeout: 3.0))->sendRequest(new PsrRequest('GET', self::URL));

        self::assertSame(12.5, $options['timeout'] ?? null);
        self::assertSame(3.0, $options['connect_timeout'] ?? null);
    }

    private function send(PsrRequest $request): Request
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->client()->sendRequest($request);

        $recorded = Http::recorded()->map(static fn(array $pair): Request => $pair[0])->all();
        self::assertCount(1, $recorded);

        return array_values($recorded)[0];
    }
}

<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Webhooks;

use Appsolutely\Sdk\Exception\InvalidConfigException;
use Appsolutely\Sdk\Exception\UnexpectedPayloadException;
use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Laravel\Tests\Support\ArrayStoreWithLockHook;
use Appsolutely\Sdk\Laravel\Tests\Support\StoreWithoutLocks;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Laravel\Webhooks\DeliveryLock;
use Appsolutely\Sdk\Testing\SignedWebhook;
use Appsolutely\Sdk\Testing\WebhookFactory;
use Appsolutely\Sdk\Webhooks\Deliveries;
use Appsolutely\Sdk\Webhooks\Events\PingEvent;
use Appsolutely\Sdk\Webhooks\Events\TypedEvent;
use Appsolutely\Sdk\Webhooks\EventType;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class WebhookRouteTest extends TestCase
{
    private const array CSRF_MIDDLEWARE = [
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    ];

    /** A real type's data in the shape its typed event reads. */
    private const array PING = ['subscription_id' => 'sub_1'];

    /** A type the SDK does not know, as a newer server may send. */
    private const string UNKNOWN_TYPE = 'example.unknown_type';

    private string $secret;

    private string $oldSecret;

    /** @var list<WebhookReceived> */
    private array $received = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->secret = self::secret('n');
        $this->oldSecret = self::secret('o');

        parent::setUp();

        Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
            $this->received[] = $event;
        });
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // As the environment variable holds them: the new secret and, during a rotation, the old one.
        $app->make('config')->set('appsolutely.webhooks.secrets', $this->secret . ', ' . $this->oldSecret);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->group(['middleware' => 'web'], static function (Router $router): void {
            Route::appsolutelyWebhooks();
            $router->post('/form', static fn(): string => 'ok')->name('form');
        });
    }

    public function testItRegistersAPostRouteAtTheConfiguredPath(): void
    {
        $route = $this->router()->getRoutes()->getByName('appsolutely.webhooks');

        self::assertNotNull($route);
        self::assertSame('appsolutely/webhooks', $route->uri());
        self::assertSame(['POST'], $route->methods());
    }

    public function testThePathCanBeGivenWhenTheRouteIsRegistered(): void
    {
        $route = Route::appsolutelyWebhooks('/hooks/appsolutely');

        self::assertSame('hooks/appsolutely', $route->uri());
    }

    public function testTheRouteIsExemptFromCsrfProtectionInTheWebGroup(): void
    {
        $router = $this->router();
        $form = $router->getRoutes()->getByName('form');
        $webhooks = $router->getRoutes()->getByName('appsolutely.webhooks');
        self::assertNotNull($form);
        self::assertNotNull($webhooks);

        // The control: the same group does check CSRF tokens on another route.
        self::assertNotSame([], self::csrfMiddleware($router->gatherRouteMiddleware($form)));
        self::assertSame([], self::csrfMiddleware($router->gatherRouteMiddleware($webhooks)));
    }

    public function testAVerifiedDeliveryIsDispatchedAsALaravelEvent(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING, mode: 'test');

        $this->deliver($delivery)->assertOk();

        self::assertCount(1, $this->received);
        $event = $this->received[0]->event;
        self::assertSame($delivery->id, $event->id);
        self::assertSame(EventType::WEBHOOK_PING, $event->type);
        self::assertSame('test', $event->mode);
        self::assertSame(self::PING, $event->data);
    }

    public function testAKnownTypeIsAlsoDispatchedAsTheSdksTypedEvent(): void
    {
        $typed = [];
        Event::listen(PingEvent::class, static function (PingEvent $event) use (&$typed): void {
            $typed[] = $event;
        });
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1']);

        $this->deliver($delivery)->assertOk();

        self::assertCount(1, $typed);
        self::assertSame('sub_1', $typed[0]->subscriptionId);
        self::assertSame($delivery->id, $typed[0]->envelope->id);
        // The generic event still arrives, so a listener for every type keeps working.
        self::assertCount(1, $this->received);
        self::assertSame(EventType::WEBHOOK_PING, $this->received[0]->event->type);
    }

    public function testAnUnknownTypeIsDispatchedOnlyAsTheGenericEvent(): void
    {
        $dispatched = [];
        Event::listen('*', static function (string $name) use (&$dispatched): void {
            $dispatched[] = $name;
        });

        $this->deliver($this->factory()->make(self::UNKNOWN_TYPE))->assertOk();

        self::assertCount(1, $this->received);
        self::assertSame([], array_values(array_filter($dispatched, static fn(string $name): bool => is_subclass_of($name, TypedEvent::class))));
    }

    public function testAKnownTypeWhoseDataBreaksItsShapeAnswers500AndDispatchesNothing(): void
    {
        Exceptions::fake();

        $this->deliver($this->factory()->make(EventType::WEBHOOK_PING, ['subscription_id' => 42]))->assertStatus(500);

        Exceptions::assertReported(UnexpectedPayloadException::class);
        self::assertSame([], $this->received);
    }

    public function testADeliverySignedWithTheOldSecretIsAcceptedDuringARotation(): void
    {
        $this->deliver((new WebhookFactory([$this->oldSecret]))->make(EventType::WEBHOOK_PING, self::PING))->assertOk();

        self::assertCount(1, $this->received);
    }

    public function testADuplicateOfAHandledDeliveryIsAcknowledgedWithoutDispatching(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        $this->deliver($delivery)->assertOk();
        $this->deliver($delivery)->assertOk();

        self::assertCount(1, $this->received);
    }

    public function testADeliveryAnotherAttemptIsHandlingAnswers409SoTheSenderRetriesLater(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        $attempt = $this->deliveryLock()->lock($delivery->id);
        self::assertInstanceOf(Lock::class, $attempt);
        self::assertTrue($attempt->get());

        $this->deliver($delivery)->assertStatus(409);
        self::assertSame([], $this->received);

        // Not recorded as handled: once the other attempt lets go, a retry runs.
        $attempt->release();
        $this->deliver($delivery)->assertOk();
        self::assertCount(1, $this->received);
    }

    public function testTheDeliveryLockIsReleasedWhenTheDeliveryIsHandledOrFails(): void
    {
        Exceptions::fake();
        $handled = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        $this->deliver($handled)->assertOk();

        $failed = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        Event::listen(WebhookReceived::class, static function (): never {
            throw new RuntimeException('The listener failed.');
        });
        $this->deliver($failed)->assertStatus(500);

        foreach ([$handled, $failed] as $delivery) {
            $lock = $this->deliveryLock()->lock($delivery->id);
            self::assertInstanceOf(Lock::class, $lock);
            self::assertTrue($lock->get(), 'The delivery lock was not released.');
        }
    }

    public function testADeliveryHandledWhileTheLockWasTakenDoesNotRunTheListenersAgain(): void
    {
        $store = new ArrayStoreWithLockHook();
        Cache::extend('hooked', fn(): Repository => Cache::repository($store));
        config()->set('cache.stores.hooked', ['driver' => 'hooked']);
        config()->set('appsolutely.cache_store', 'hooked');
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        // Another attempt finishes after this one's first check and before
        // it takes the lock.
        $store->beforeLock = function () use ($delivery): void {
            $deliveries = $this->app?->make(Deliveries::class);
            self::assertInstanceOf(Deliveries::class, $deliveries);
            $deliveries->markHandled($delivery->id);
        };

        $this->deliver($delivery)->assertOk();

        self::assertSame([], $this->received);
    }

    public function testAStoreWithoutLocksStillHandlesDeliveries(): void
    {
        Cache::extend('without-locks', fn(): Repository => Cache::repository(new StoreWithoutLocks()));
        config()->set('cache.stores.nolocks', ['driver' => 'without-locks']);
        config()->set('appsolutely.cache_store', 'nolocks');
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        $this->deliver($delivery)->assertOk();
        $this->deliver($delivery)->assertOk();

        self::assertCount(1, $this->received);
    }

    public function testEachDeliveriesNamespaceTakesItsOwnLock(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        config()->set('appsolutely.webhooks.deliveries_namespace', 'orders');
        $orders = $this->deliveryLock()->lock($delivery->id);
        self::assertInstanceOf(Lock::class, $orders);
        self::assertTrue($orders->get());

        config()->set('appsolutely.webhooks.deliveries_namespace', 'billing');
        $this->deliver($delivery)->assertOk();
    }

    public function testAListenerFailureAnswers500SoTheSenderRetriesAndTheRetryIsHandled(): void
    {
        Exceptions::fake();
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);
        $calls = 0;
        Event::listen(WebhookReceived::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('The listener failed.');
            }
        });

        $response = $this->deliver($delivery);

        $response->assertStatus(500);
        self::assertStringNotContainsString('The listener failed.', (string) $response->baseResponse->getContent());
        Exceptions::assertReported(RuntimeException::class);

        $this->deliver($delivery)->assertOk();
        $this->deliver($delivery)->assertOk();

        // Dispatched on the failed attempt and on its retry, not on the duplicate.
        self::assertCount(2, $this->received);
    }

    public function testATypedListenerFailureMakesTheRetryRunTheListenersThatSucceededAgain(): void
    {
        Exceptions::fake();
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1']);
        $calls = 0;
        Event::listen(PingEvent::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('The typed listener failed.');
            }
        });

        $this->deliver($delivery)->assertStatus(500);
        $this->deliver($delivery)->assertOk();

        // The catch-all listener returned on the failed attempt and still
        // runs on the retry: nothing records which listeners succeeded.
        self::assertCount(2, $this->received);
        self::assertSame([$delivery->id, $delivery->id], array_map(static fn(WebhookReceived $received): string => $received->event->id, $this->received));
    }

    public function testAnInvalidSignatureIsRefusedWithoutDetail(): void
    {
        $delivery = (new WebhookFactory([self::secret('x')]))->make(EventType::WEBHOOK_PING, self::PING);

        $this->assertRefused($this->deliver($delivery), 'No v1 signature in webhook-signature matches the body');
    }

    public function testATamperedBodyIsRefused(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        $this->assertRefused($this->deliver(new SignedWebhook(
            $delivery->id,
            str_replace('sub_1', 'sub_2', $delivery->body),
            $delivery->headers,
        )), 'No v1 signature in webhook-signature matches the body');
    }

    public function testADeliveryWithoutTheSignatureHeadersIsRefused(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        $this->assertRefused($this->call('POST', '/appsolutely/webhooks', server: ['CONTENT_TYPE' => 'application/json'], content: $delivery->body), 'The delivery has no webhook-id header.');
    }

    public function testAStaleDeliveryIsRefused(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        $this->travel(301)->seconds();

        $this->assertRefused($this->deliver($delivery), 'The webhook-timestamp is more than 300 seconds from now');
    }

    public function testASignedBodyNamingAnotherIdThanItsHeaderIsRefused(): void
    {
        $this->assertRefused($this->deliver($this->handSigned(headerId: 'msg_in_header', bodyId: 'msg_in_body')), 'The event id in the body is not the webhook-id header.');
    }

    public function testAHandSignedDeliveryWhoseIdsMatchIsAccepted(): void
    {
        // The twin of the refusal above, built the same way: it proves the
        // refusal comes from the id check, not from the hand-made signature.
        $this->deliver($this->handSigned(headerId: 'msg_same', bodyId: 'msg_same'))->assertOk();

        self::assertCount(1, $this->received);
        self::assertSame('msg_same', $this->received[0]->event->id);
    }

    public function testEachDeliveriesNamespaceKeepsItsOwnRecord(): void
    {
        $delivery = $this->factory()->make(EventType::WEBHOOK_PING, self::PING);

        config()->set('appsolutely.webhooks.deliveries_namespace', 'orders');
        $this->deliver($delivery)->assertOk();
        config()->set('appsolutely.webhooks.deliveries_namespace', 'billing');
        $this->deliver($delivery)->assertOk();

        self::assertCount(2, $this->received);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function missingSecrets(): iterable
    {
        yield 'empty' => [''];
        yield 'only separators and spaces' => [' , '];
        yield 'empty list' => [[]];
        yield 'not set' => [null];
    }

    #[DataProvider('missingSecrets')]
    public function testMissingSecretsAreAConfigurationErrorNamingTheVariableNotARefusal(mixed $secrets): void
    {
        config()->set('appsolutely.webhooks.secrets', $secrets);
        $this->withoutExceptionHandling();

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('APPSOLUTELY_WEBHOOK_SECRETS');

        $this->deliver($this->factory()->make(EventType::WEBHOOK_PING, self::PING));
    }

    /**
     * A distinct valid secret per character.
     */
    private static function secret(string $character): string
    {
        return 'whsec_' . base64_encode(str_repeat($character, 32));
    }

    /**
     * Signs, as Standard Webhooks does, a body naming one id under a
     * header naming another, which WebhookFactory cannot produce.
     */
    private function handSigned(string $headerId, string $bodyId): SignedWebhook
    {
        $body = $this->factory()->make(EventType::WEBHOOK_PING, self::PING, id: $bodyId)->body;
        $timestamp = (string) Date::now()->getTimestamp();
        $key = base64_decode(substr($this->secret, strlen('whsec_')), true);
        self::assertIsString($key);
        $signature = base64_encode(hash_hmac('sha256', $headerId . '.' . $timestamp . '.' . $body, $key, true));

        return new SignedWebhook($headerId, $body, [
            'webhook-id' => $headerId,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,' . $signature,
        ]);
    }

    private function deliveryLock(): DeliveryLock
    {
        $lock = $this->app?->make(DeliveryLock::class);
        self::assertInstanceOf(DeliveryLock::class, $lock);

        return $lock;
    }

    private function factory(): WebhookFactory
    {
        return new WebhookFactory([$this->secret]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function deliver(SignedWebhook $delivery): TestResponse
    {
        return $this->call(
            'POST',
            '/appsolutely/webhooks',
            server: $this->transformHeadersToServerVars([...$delivery->headers, 'Content-Type' => 'application/json']),
            content: $delivery->body,
        );
    }

    /**
     * Refused without detail to the sender, with the reason in the log.
     *
     * @param TestResponse<Response> $response
     */
    private function assertRefused(TestResponse $response, string $reason): void
    {
        $response->assertStatus(400);
        self::assertSame('Invalid webhook delivery.', $response->baseResponse->getContent());
        self::assertSame([], $this->received);

        $refusals = array_values(array_filter($this->logged, static fn(MessageLogged $log): bool => $log->message === 'An Appsolutely webhook delivery failed verification.'));
        self::assertCount(1, $refusals);
        self::assertSame('warning', $refusals[0]->level);
        $logged = $refusals[0]->context['reason'] ?? null;
        self::assertIsString($logged);
        self::assertStringContainsString($reason, $logged);
    }

    private function router(): Router
    {
        // The HTTP kernel hands its middleware groups to the router when it
        // is built, as it is for every real request.
        $this->app?->make(Kernel::class);
        $router = $this->app?->make(Router::class);
        self::assertInstanceOf(Router::class, $router);

        return $router;
    }

    /**
     * @param array<mixed> $middleware
     * @return list<string>
     */
    private static function csrfMiddleware(array $middleware): array
    {
        $found = [];
        foreach ($middleware as $name) {
            if (!is_string($name)) {
                continue;
            }
            foreach (self::CSRF_MIDDLEWARE as $csrf) {
                if (is_a($name, $csrf, true)) {
                    $found[] = $name;
                }
            }
        }

        return $found;
    }
}

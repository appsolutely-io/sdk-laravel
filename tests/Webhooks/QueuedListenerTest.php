<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Webhooks;

use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Laravel\Testing\InteractsWithAppsolutelyWebhooks;
use Appsolutely\Sdk\Laravel\Tests\Support\QueuedWebhookListener;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Webhooks\Events\OrderPaidEvent;
use Appsolutely\Sdk\Webhooks\EventType;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * Both events a delivery dispatches reach a queued listener: the queue
 * serializes them and the worker gets back the same delivery.
 */
final class QueuedListenerTest extends TestCase
{
    use InteractsWithAppsolutelyWebhooks;

    private const array PAID_ORDER = [
        'id' => 'A-1001',
        'reference' => 'A-1001',
        'mode' => 'test',
        'amount' => 4900,
        'currency' => 'USD',
        'discounted_amount' => 0,
        'shipping_amount' => 0,
        'tax_amount' => 0,
        'tax_included' => false,
        'total_amount' => 4900,
        'total_shown' => '$49.00',
        'updated_at' => '2026-10-13T10:00:00Z',
        'items' => [],
    ];

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('appsolutely.webhooks.secrets', 'whsec_' . base64_encode(str_repeat('s', 32)));
        // The sync queue still serializes each job's payload and unserializes it to run it.
        $config->set('queue.default', 'sync');
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        Route::appsolutelyWebhooks();
    }

    protected function setUp(): void
    {
        parent::setUp();

        QueuedWebhookListener::$handled = [];
        Event::listen(WebhookReceived::class, QueuedWebhookListener::class);
        Event::listen(OrderPaidEvent::class, QueuedWebhookListener::class);
    }

    public function testBothEventsReachAQueuedListenerThroughSerialization(): void
    {
        $payloads = [];
        Event::listen(JobProcessing::class, static function (JobProcessing $event) use (&$payloads): void {
            self::assertInstanceOf(Job::class, $event->job);
            $payloads[] = $event->job->getRawBody();
        });

        $delivery = $this->appsolutelyWebhookFactory()->make(EventType::ORDER_PAID, self::PAID_ORDER, mode: 'test');
        $this->sendAppsolutelyWebhook($delivery)->assertOk();

        // Two jobs went through the queue's serialized payload, one per event.
        self::assertCount(2, $payloads);
        self::assertCount(2, QueuedWebhookListener::$handled);
        [$received, $paid] = QueuedWebhookListener::$handled;

        self::assertInstanceOf(WebhookReceived::class, $received);
        self::assertSame($delivery->id, $received->event->id);
        self::assertSame(EventType::ORDER_PAID, $received->event->type);
        self::assertSame(self::PAID_ORDER, $received->event->data);

        self::assertInstanceOf(OrderPaidEvent::class, $paid);
        self::assertSame('A-1001', $paid->order->id);
        self::assertSame(4900, $paid->order->totalAmount);
        self::assertSame($delivery->id, $paid->envelope->id);
    }
}

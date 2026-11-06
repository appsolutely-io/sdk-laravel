<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Testing;

use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Laravel\Testing\InteractsWithAppsolutelyWebhooks;
use Appsolutely\Sdk\Laravel\Tests\TestCase;
use Appsolutely\Sdk\Webhooks\Events\OrderPaidEvent;
use Appsolutely\Sdk\Webhooks\EventType;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

final class InteractsWithAppsolutelyWebhooksTest extends TestCase
{
    use InteractsWithAppsolutelyWebhooks;

    /** An order.paid delivery's data, in the shape OrderPaidEvent reads. */
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

    /** @var list<WebhookReceived> */
    private array $received = [];

    /** @var list<string> */
    private array $paid = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
            $this->received[] = $event;
        });
        Event::listen(OrderPaidEvent::class, function (OrderPaidEvent $event): void {
            $this->paid[] = $event->order->id;
        });
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('appsolutely.webhooks.secrets', 'whsec_' . base64_encode(str_repeat('s', 32)));
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        Route::appsolutelyWebhooks('/integrations/appsolutely');
    }

    public function testItSendsASignedDeliveryToTheRegisteredRoute(): void
    {
        $this->postAppsolutelyWebhook(EventType::ORDER_PAID, self::PAID_ORDER, mode: 'test')->assertOk();

        self::assertCount(1, $this->received);
        self::assertSame(EventType::ORDER_PAID, $this->received[0]->event->type);
        self::assertSame(self::PAID_ORDER, $this->received[0]->event->data);
        self::assertSame('test', $this->received[0]->event->mode);
        self::assertSame(['A-1001'], $this->paid);
    }

    public function testItSignsWithLaravelsClockSoTravellingInTimeStillVerifies(): void
    {
        $this->travel(2)->days();

        $this->postAppsolutelyWebhook(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1'])->assertOk();

        self::assertCount(1, $this->received);
    }

    public function testADeliveryCanBeSentAgainToExerciseDuplicates(): void
    {
        $delivery = $this->appsolutelyWebhookFactory()->make(EventType::WEBHOOK_PING, ['subscription_id' => 'sub_1'], id: 'msg_1');

        $this->sendAppsolutelyWebhook($delivery)->assertOk();
        $this->sendAppsolutelyWebhook($delivery)->assertOk();

        self::assertCount(1, $this->received);
        self::assertSame('msg_1', $this->received[0]->event->id);
    }
}

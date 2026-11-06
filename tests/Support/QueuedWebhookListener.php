<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Webhooks\Events\OrderPaidEvent;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener, as an application registers one for slow work: the
 * queue serializes the event it receives and the worker unserializes it.
 */
final class QueuedWebhookListener implements ShouldQueue
{
    /** @var list<WebhookReceived|OrderPaidEvent> */
    public static array $handled = [];

    public function handle(WebhookReceived|OrderPaidEvent $event): void
    {
        self::$handled[] = $event;
    }
}
